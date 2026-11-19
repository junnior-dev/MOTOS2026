<?php

namespace App\Jobs;

use App\Services\Tfhka\EmisorFiscal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EmitirDocumentoFiscal · emisión de la factura TFHKA FUERA del request de la venta.
 *
 * Por qué existe: antes, la venta llamaba a EmisorFiscal::emitirVenta() dentro del
 * request HTTP; un corte de red con TFHKA dejaba al cajero esperando o, peor, varias
 * ventas trabadas. Ahora la venta termina SIEMPRE en milisegundos y este trabajo
 * encola la emisión (driver «database», tabla public.jobs — SQL 45) con:
 *
 *   · Reintentos con backoff creciente (config/tfhka.php cola_backoff) dentro de una
 *     ventana total (cola_horas). Un problema transitorio de red se cura solo.
 *   · Idempotencia heredada del emisor: emitirVenta() con forzar=false NO re-emite si
 *     la venta ya está EMITIDA y, ante un envío en duda, pregunta a TFHKA antes de
 *     reemitir (recuperarVenta). Por eso este trabajo nunca pasa forzar=true.
 *   · Sin duplicados: lock de colas (WithoutOverlapping por venta), de modo que si el
 *     administrador aprieta «emitir» mientras el worker procesa, no se emiten dos
 *     facturas para la misma venta.
 *   · afterCommit: se encola después del COMMIT de la venta, nunca antes.
 *   · failed(): si se agotan los reintentos, la venta ya quedó en ERROR (el emisor la
 *     marca antes de relanzar) y el fallo queda registrado en failed_jobs para el
 *     comando fiscal:reintentar y para la pantalla de facturación digital.
 *
 * El PDF y el número de control quedan en ventas.*, exactamente como en la vía
 * síncrona; la pantalla de facturación digital es la misma.
 */
class EmitirDocumentoFiscal implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reintentos máximo por trabajo: 5 con backoff creciente (~81 min en total).
     *  Nota de Laravel: si el trabajo definiera retryUntil(), el worker IGNORARÍA
     *  $tries y solo contaría la ventana de tiempo — por eso se usa UNA estrategia
     *  (intentos acotados) y la recuperación de brotes largos la hace
     *  fiscal:reintentar volviendo a encolar ventas sin factura. */
    public int $tries = 5;

    public function __construct(public int $ventaId, public ?int $usuarioId = null)
    {
        // Encolar solo tras el COMMIT que creó la venta (la propiedad vive en Queueable).
        $this->afterCommit = true;
        // Cola dedicada: el worker del servidor la consume con --queue=fiscal y
        // queue:monitor vigila database:fiscal. (La fija el BUS por propiedad,
        // no por método: ver Illuminate\Bus\Dispatcher::dispatchToQueue.)
        $this->queue = 'fiscal';
    }

    /** Cola dedicada «fiscal» (se fija en el constructor; el worker la consume). */
    /** No encolar dos veces la misma venta mientras el trabajo vive (no duplicar factura). */
    public function uniqueId(): int
    {
        return $this->ventaId;
    }

    /** Duración del lock antirrepetido por venta. */
    public function uniqueFor(): int
    {
        return (int) max(60, ...config('tfhka.cola_backoff', [15, 60, 300, 900, 3600]));
    }

    /** Espera entre reintentos: crece (red caída, TFHKA en mantenimiento).
     * @return array<int, int> */
    public function backoff(): array
    {
        return config('tfhka.cola_backoff', [15, 60, 300, 900, 3600]);
    }

    /** El emisor es idempotente: este trabajo puede correrse cuantas veces sea. */
    public function handle(EmisorFiscal $fiscal): void
    {
        $fiscal->emitirVenta($this->ventaId, $this->usuarioId);
    }

    /** Se agotaron los reintentos: bitácora. La venta ya está en ERROR (la marcó el emisor). */
    public function failed(Throwable $e): void
    {
        Log::error('TFHKA: emisión agotada para la venta '.$this->ventaId.': '.$e->getMessage(), [
            'venta_id' => $this->ventaId,
        ]);
    }
}
