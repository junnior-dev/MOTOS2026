<?php

/*
 * FiscalColaTest · pipeline REAL de emisión fiscal encolada (SQL 45 + Job).
 * Sin mocks de la cola: se usa el driver «database» auténtico, la tabla
 * public.jobs, el lock antirrepetido y el worker real (artisan queue:work
 * --once). El único doble es el emisor TFHKA (no hay red en las pruebas):
 * EmisorFiscal se sustituye por un tallo que grabe las llamadas.
 */

use App\Jobs\EmitirDocumentoFiscal;
use App\Services\Tfhka\EmisorFiscal;
use App\Services\Tfhka\TfhkaException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Tallo del emisor: graba cada llamada y se programa para contestar como se quiera. */
class EmisorGrabador extends EmisorFiscal
{
    /** @var list<array{venta_id: int, usuario_id: ?int, forzar: bool}> */
    public array $llamadas = [];

    /** @var list<string> */
    public array $respuestas = [];

    /** @return array{numero_control: string, numeroDocumento: string} */
    public function emitirVenta(int $ventaId, ?int $usuarioId = null, bool $forzar = false): array
    {
        $this->llamadas[] = ['venta_id' => $ventaId, 'usuario_id' => $usuarioId, 'forzar' => $forzar];
        $siguiente = $this->respuestas[0] ?? 'OK';
        array_shift($this->respuestas);
        if ($siguiente !== 'OK') {
            throw new TfhkaException($siguiente);
        }

        return ['numero_control' => 'CTRL-TEST', 'numeroDocumento' => 'F-TEST'];
    }
}

/** El tallo instalado en el contenedor para esta prueba (vinculado como EmisorFiscal). */
function emisorGrabador(): EmisorGrabador
{
    $emisor = app(EmisorFiscal::class);
    assert($emisor instanceof EmisorGrabador);

    return $emisor;
}

beforeEach(function () {
    // Cola auténtica por base de datos, backoff instantáneo para las pruebas
    config([
        'queue.default' => 'database',
        'tfhka.cola_backoff' => [0, 0, 0, 0, 0],
    ]);
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
    app()->instance(EmisorFiscal::class, new EmisorGrabador);
});

it('encola de verdad en la tabla jobs con la cola fiscal', function () {
    EmitirDocumentoFiscal::dispatch(77, 1);

    expect(DB::table('jobs')->where('queue', 'fiscal')->count())->toBe(1);
});

it('solo se encola después del COMMIT de la venta (afterCommit)', function () {
    DB::transaction(function () {
        EmitirDocumentoFiscal::dispatch(88, 1);
        // dentro de la transacción el trabajo NO debe estar visible aún
        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->where('queue', 'fiscal')->count())->toBe(1);
});

it('el worker real procesa el trabajo y llama al emisor sin forzar (idempotencia)', function () {
    EmitirDocumentoFiscal::dispatch(77, 5);

    $codigo = Artisan::call('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => '0']);

    expect($codigo)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(emisorGrabador()->llamadas)->toHaveCount(1)
        ->and(emisorGrabador()->llamadas[0]['venta_id'])->toBe(77)
        ->and(emisorGrabador()->llamadas[0]['usuario_id'])->toBe(5)
        ->and(emisorGrabador()->llamadas[0]['forzar'])->toBeFalse();
});

it('reintenta solo ante fallo de TFHKA y finalmente deja el trabajo en failed_jobs', function () {
    emisorGrabador()->respuestas = ['TFHKA caída 1', 'TFHKA caída 2', 'TFHKA caída 3', 'TFHKA caída 4', 'TFHKA caída 5'];

    EmitirDocumentoFiscal::dispatch(77, 1);

    // El worker de producción corre con --tries (igual que el supervisor lo
    // arranca); sin esa opción el CLI ignora el $tries del job y libera para
    // siempre. 5 intentos del job: el 5º fallo definitivo → failed_jobs.
    for ($i = 0; $i < 6; $i++) {
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => '0', '--tries' => '5']);
    }

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(emisorGrabador()->llamadas)->toHaveCount(5)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('TFHKA caída');
});

it('un fallo con red recuperada se cura solo: 2º intento emite', function () {
    emisorGrabador()->respuestas = ['TFHKA caída', 'OK'];

    EmitirDocumentoFiscal::dispatch(99, 1);

    Artisan::call('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => '0', '--tries' => '5']);
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'fiscal', '--sleep' => '0', '--tries' => '5']);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(emisorGrabador()->llamadas)->toHaveCount(2);
});

it('no encola dos veces la misma venta mientras el trabajo sigue vivo (unique)', function () {
    EmitirDocumentoFiscal::dispatch(77, 1);
    EmitirDocumentoFiscal::dispatch(77, 1); // doble clic del cajero: debe caer en el lock

    expect(DB::table('jobs')->where('queue', 'fiscal')->count())->toBe(1);
});

it('fiscal:reintentar no hace nada si la facturación digital no está activa', function () {
    $codigo = Artisan::call('fiscal:reintentar');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->toContain('no está activa')
        ->and(DB::table('jobs')->count())->toBe(0);
});
