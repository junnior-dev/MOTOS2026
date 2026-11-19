<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Genera el XML de la Relación Informativa de Retenciones de ISLR que la tienda
 * presenta por el Portal Fiscal del SENIAT (manual TRI.GR.03.0013): un
 * <DetalleRetencion> por retención ACTIVA del período, con las reglas del manual
 * (factura últimos 10 dígitos o «0», control secuencial ≤ 8 o «NA», código del
 * Anexo 6.1 por tipo de beneficiario, montos con punto decimal).
 *
 * El mismo archivo se puede descargar desde la página de Retenciones de ISLR
 * (botón «XML SENIAT», /retenciones-islr/xml/AAAA-MM). Este comando sirve para
 * el cierre mensual sin necesitar una sesión abierta:
 *
 *   php artisan fiscal:islr-xml                # el mes actual
 *   php artisan fiscal:islr-xml 2026-11
 */
class ExportarIslrXml extends Command
{
    protected $signature = 'fiscal:islr-xml {periodo? : Mes del período en formato AAAA-MM (por defecto, el mes actual)}';

    protected $description = 'Genera el XML de la relación informativa de retenciones de ISLR para el Portal Fiscal del SENIAT (TRI.GR.03.0013)';

    public function handle(): int
    {
        $periodo = (string) ($this->argument('periodo') ?? now()->format('Y-m'));
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodo, $p)) {
            $this->error('El período debe tener el formato AAAA-MM, por ejemplo 2026-11.');
            return self::FAILURE;
        }
        [$ano, $mes] = [(int) $p[1], (int) $p[2]];
        try {
            $n = (int) DB::scalar(
                "SELECT count(*) FROM retenciones_islr WHERE estado = 'ACTIVA' AND fecha BETWEEN ?::date AND (?::date + interval '1 month - 1 day')::date",
                ["{$ano}-{$mes}-01", "{$ano}-{$mes}-01"]);
            if ($n === 0) {
                $this->line("No hay retenciones ACTIVAS registradas en {$periodo}: la relación informativa necesita al menos un registro (el Portal Fiscal rechaza un archivo vacío).");
                return self::FAILURE;
            }
            $xml = DB::scalar('SELECT fn_retenciones_islr_xml(?::int, ?::int)', [$ano, $mes]);
            $rif = trim((string) DB::scalar('SELECT replace(rif, \'-\', \'\') FROM negocio WHERE id = 1'));
            $salida = storage_path("app/islr/retenciones_islr_{$ano}{$mes}_{$rif}.xml");
            if (!is_dir(dirname($salida))) mkdir(dirname($salida), 0775, true);
            file_put_contents($salida, $xml);
        } catch (Throwable $e) {
            $this->error('No se pudo generar el XML: ' . $e->getMessage());
            $this->line('Si el mensaje pide el RIF del negocio o un código del Anexo 6.1, corrige esos datos en la base y vuelve a intentarlo.');
            return self::FAILURE;
        }

        $retenido = (float) DB::scalar(
            "SELECT COALESCE(sum(retenido_ves), 0) FROM retenciones_islr WHERE estado = 'ACTIVA' AND fecha BETWEEN ?::date AND (?::date + interval '1 month - 1 day')::date",
            ["{$ano}-{$mes}-01", "{$ano}-{$mes}-01"]);
        $this->info("XML generado: {$salida}");
        $this->line("Retenciones incluidas: {$n} · retenido del período: Bs " . number_format($retenido, 2, ',', '.'));
        $this->line('Preséntalo por el Portal Fiscal del SENIAT (seniat.gob.ve), opción ISLR → Declaración de Salarios y Otras Retenciones, y consérvalo con su acuse de recibo.');

        return self::SUCCESS;
    }
}
