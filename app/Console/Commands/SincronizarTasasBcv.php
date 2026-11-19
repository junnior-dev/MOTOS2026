<?php

namespace App\Console\Commands;

use App\Services\BcvService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sincroniza las tasas oficiales del BCV desde la consola: la misma carga que
 * hace el botón «Sincronizar con BCV» de la página de Tasas de cambio, pero
 * sin necesitar una sesión abierta. El programador la corre cada día a las
 * 8:00 a.m., hora de Caracas (ver routes/console.php); también puede lanzarse
 * a mano: php artisan tasas:bcv --forzar.
 */
class SincronizarTasasBcv extends Command
{
    protected $signature = 'tasas:bcv {--forzar : Ignora la caché de 30 minutos del portal del BCV}';

    protected $description = 'Carga las tasas oficiales del BCV (USD, EUR, COP y monedas activas) del día';

    public function handle(BcvService $bcv): int
    {
        try {
            $r = $bcv->tasas(forzar: (bool) $this->option('forzar'));
        } catch (Throwable $e) {
            $this->error('No se pudo consultar el BCV: ' . $e->getMessage());
            $this->line('La tasa del día puede registrarse a mano en la página de Tasas de cambio.');

            return self::FAILURE;
        }

        $hoyDb = (string) DB::scalar('SELECT fn_hoy()');
        $fechaBcv = $r['fecha'] ?? $hoyDb;
        $cargadas = [];
        $omitidas = [];

        DB::transaction(function () use ($r, $hoyDb, $fechaBcv, &$cargadas, &$omitidas) {
            $activas = array_map(fn ($row) => $row->codigo, DB::select('SELECT codigo FROM monedas WHERE activa'));
            $monedas = array_unique(array_merge(['USD', 'EUR', 'COP'], $activas));

            foreach ($monedas as $m) {
                if ($m === 'VES') {
                    continue;
                }
                $tasa = (float) ($r['tasas'][$m] ?? 0);
                if ($tasa <= 0) {
                    $omitidas[] = $m;
                    continue;
                }
                if ($fechaBcv > $hoyDb) {
                    DB::statement("CALL sp_registrar_tasa(?,?,'BCV',true,?,?,'VES')", [round($tasa, 8), $hoyDb, null, $m]);
                }
                DB::statement("CALL sp_registrar_tasa(?,?,'BCV',true,?,?,'VES')", [round($tasa, 8), $fechaBcv, null, $m]);
                $cargadas[] = $m;
            }
        });

        Cache::put('tasas.ultima_bcv', now()->format('d/m/Y H:i'), now()->addDay());

        $this->info('Fecha valor del BCV: ' . $fechaBcv);
        $this->info('Tasas cargadas: ' . (implode(', ', $cargadas) ?: 'ninguna'));
        if ($omitidas) {
            $this->warn('Sin cotización en BCV para: ' . implode(', ', $omitidas));
        }

        foreach ($cargadas as $m) {
            $ultima = DB::scalar("SELECT tasa FROM tasas_cambio WHERE moneda_origen = ? AND moneda_destino = 'VES' ORDER BY fecha_valor DESC LIMIT 1", [$m]);
            $this->line(sprintf('  %s → Bs %s', $m, rtrim(rtrim(number_format((float) $ultima, 8, '.', ''), '0'), '.')));
        }

        return self::SUCCESS;
    }
}
