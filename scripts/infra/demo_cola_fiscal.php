<?php

/*
 * demo_cola_fiscal.php · DEMOSTRACIÓN REAL del pipeline de emisión fiscal encolada.
 *
 * Despacha App\Jobs\EmitirDocumentoFiscal contra la base indicada en el entorno,
 * usando el driver «database» auténtico. El worker real (php artisan queue:work)
 * lo procesa: con TFHKA sin configurar el emisor lanza su excepción normal y se
 * ve el ciclo completo encola → intento → reintento → failed_jobs.
 *
 * Uso (desde la raíz del proyecto, con QUEUE_CONNECTION=database en el entorno):
 *   php scripts/infra/demo_cola_fiscal.php 1        # encola la venta 1
 *   php artisan queue:work --once --queue=fiscal --tries=5 --sleep=0
 *   php artisan queue:failed
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Jobs\EmitirDocumentoFiscal;

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ventaId = (int) ($argv[1] ?? 1);
$activo = (new App\Services\Tfhka\EmisorFiscal())->activo();

echo "Tienda: ".Illuminate\Support\Facades\DB::connection()->getDatabaseName()."\n";
echo 'TFHKA activo: '.($activo ? 'sí (la venta intentará emitir de verdad)' : 'no (el emisor lanzará su excepción normal — se demuestra el ciclo de reintentos)')."\n";

EmitirDocumentoFiscal::dispatch($ventaId);

$cola = Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'fiscal')->count();
echo "Trabajo encolado para la venta {$ventaId}. Trabajos en la cola «fiscal»: {$cola}\n";
echo "Ahora corre: php artisan queue:work --once --queue=fiscal --tries=5 --sleep=0\n";
