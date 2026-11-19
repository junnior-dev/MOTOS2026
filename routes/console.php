<?php

use App\Http\Controllers\DeclaracionIslrController;
use App\Jobs\EmitirDocumentoFiscal;
use App\Services\Tfhka\EmisorFiscal;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Alertas de ISLR: retenciones por enterar y vencimientos cercanos. Termina con
 * código 1 si hay algo vencido. Se ejecuta cada día si el programador de Laravel
 * está activo (php artisan schedule:run cada minuto).
 */
Artisan::command('islr:alertas {--dias=7 : Días de anticipación}', function () {
    $vencido = false;
    $hubo = false;
    foreach (DeclaracionIslrController::alertas(max(0, (int) $this->option('dias'))) as $a) {
        $hubo = true;
        $vencido = $vencido || $a['gravedad'] === 'VENCIDO';
        $linea = '['.$a['gravedad'].'] '.$a['mensaje'];
        $a['gravedad'] === 'VENCIDO' ? $this->error($linea) : $this->warn($linea);
        Log::warning('ISLR: '.$linea);
    }
    if (! $hubo) {
        $this->info('Sin alertas de ISLR.');
    }

    return $vencido ? 1 : 0;
})->purpose('Avisa de retenciones de ISLR por enterar y de vencimientos de ISLR cercanos');

Schedule::command('islr:alertas')->dailyAt('07:00')->timezone('America/Caracas');

/*
 * Tasas de cambio: el BCV publica la tasa oficial cada día hábil en la mañana.
 * Se consulta a las 8:00 a.m. (hora de Caracas) y, si el portal aún no la tiene,
 * se reintenta cada hora hasta las 2:00 p.m. Sin clave ni usuario: el comando
 * tasas:bcv registra las tasas con la misma lógica del botón «Sincronizar con
 * BCV» de la página de Tasas.
 */
Schedule::command('tasas:bcv --forzar')->dailyAt('08:00')->timezone('America/Caracas');
Schedule::command('tasas:bcv --forzar')->hourly()->between('09:00', '14:00')->timezone('America/Caracas');

/*
 * Reintento fiscal de respaldo (App\Jobs\EmitirDocumentoFiscal · SQL 45):
 * ventas COMPLETADAS cuya factura sigue sin confirmar (PENDIENTE/ERROR) se
 * vuelven a encolar. El caso normal lo cubre el backoff del propio trabajo;
 * este comando cubre la parada de emergencia: si la tienda estuvo sin luz o
 * sin red un rato y el worker murió con trabajos a medias, al volver, todo lo
 * que quedó atrás se encola de nuevo en segundos. Con --minutos se elige la
 * antigüedad mínima (por defecto 10) para no chocar con el backoff vigente.
 */
Artisan::command('fiscal:reintentar {--minutos=10 : Antigüedad mínima de la venta} {--limite=50 : Máximo de ventas a reencolar}', function () {
    $fiscal = new EmisorFiscal;
    if (! $fiscal->activo()) {
        $this->info('Facturación digital no está activa: nada que reintentar.');

        return 0;
    }

    $minutos = max(1, (int) $this->option('minutos'));
    $limite = max(1, (int) $this->option('limite'));
    $ventas = DB::select(
        "SELECT id FROM ventas
          WHERE estado = 'COMPLETADA'
            AND COALESCE(tfhka_estado, '') <> 'EMITIDA'
            AND fecha_hora < now() - make_interval(mins => ?)
          ORDER BY id LIMIT ?",
        [$minutos, $limite]
    );

    foreach ($ventas as $v) {
        EmitirDocumentoFiscal::dispatch((int) $v->id);
    }

    $this->info(count($ventas) > 0
        ? 'Reencoladas '.count($ventas).' facturas pendientes (más antiguas que '.$minutos.' min).'
        : 'Sin facturas pendientes de reintento.');

    return 0;
})->purpose('Reencola la emisión fiscal de ventas completadas que siguen sin factura confirmada');

Schedule::command('fiscal:reintentar')->everyTenMinutes()->withoutOverlapping()->timezone('America/Caracas');

/*
 * Vigilancia de la cola fiscal: si la tabla public.jobs supera el umbral
 * (worker caído con caja abierta, TFHKA degradado por horas), el comando
 * termina con código 1 y el programador lo reporta en el log — la señal que
 * el orquestador/supervisor usa para avisar al administrador.
 */
Schedule::command('queue:monitor database:fiscal')->hourly()->withoutOverlapping()->timezone('America/Caracas');
