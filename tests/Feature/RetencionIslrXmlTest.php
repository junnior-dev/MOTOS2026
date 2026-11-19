<?php

/*
 * RetencionIslrXmlTest · la relación informativa XML que la tienda presenta por el
 * Portal Fiscal del SENIAT (manual técnico TRI.GR.03.0013 «Retenciones de ISLR»):
 * fn_retenciones_islr_xml() construye <RelacionRetencionesISLR> con UN
 * <DetalleRetencion> por retención ACTIVA del período y las reglas del manual —
 * factura máx 10 alfanuméricos (los ÚLTIMOS diez) o «0», número de control solo
 * el secuencial numérico ≤ 8 dígitos o «NA», código del Anexo 6.1 (Decreto 1.808)
 * según el tipo de beneficiario, montos con punto decimal, anuladas fuera,
 * períodos sin registros sin archivo y RIFs inválidos rechazados antes de generar.
 */

use Illuminate\Support\Facades\DB;

function registrarRetencion(string $tipo, string $concepto, ?string $documento, string $fecha, float $monto = 10000): int
{
    return (int) DB::scalar(
        'SELECT fn_registrar_retencion_islr(?::date, ?::text, ?::text, ?::bpchar, ?::text, ?::text, ?::numeric, ?::bpchar, ?::text, ?::bigint, ?::text, ?::bigint)',
        [$fecha, $tipo === 'N' ? 'Diseñador Freelance' : 'Consultora Contable C.A.', $tipo === 'N' ? 'V123456781' : 'J308554839',
            $tipo, $concepto, $documento, $monto, 'VES', 'BANCOS', null, null, adminId()]);
}

function xmlMes(int $ano, int $mes): ?string
{
    return DB::scalar('SELECT fn_retenciones_islr_xml(?::int, ?::int)', [$ano, $mes]);
}

function mesActual(): array
{
    $hoy = (string) DB::scalar('SELECT fn_hoy()');
    [$a, $m] = array_map(intval(...), explode('-', substr($hoy, 0, 7)));
    return [$a, $m];
}

function detalle(string $xml, string $rif): string
{
    preg_match('/<DetalleRetencion><RifRetenido>' . $rif . '<\/RifRetenido>.*?<\/DetalleRetencion>/s', $xml, $m);
    return $m[0] ?? '';
}

beforeEach(function () {
    $this->actingAs(\App\Models\Usuario::find(adminId()));
    // La UT vigente que exige el cálculo del sustraendo (Bs 43 = la semilla del 40).
    [$a, $m] = mesActual();
    DB::statement('SELECT fn_islr_guardar_ut(?::date, 43, ?::text, ?::bigint)',
        [DB::scalar('SELECT fn_hoy()'), 'Test del XML de retenciones ISLR', adminId()]);
    // Partida limpia del mes bajo prueba (los clones conservan datos de otras corridas;
    // la guardia de la base prohíbe borrar: se ANULAN con motivo, igual que haría el usuario).
    DB::statement("UPDATE retenciones_islr SET estado = 'ANULADA', anulada_en = now(), motivo_anulacion = 'limpieza del test del XML'
                   WHERE fecha BETWEEN ?::date AND (?::date + interval '1 month - 1 day')::date AND estado = 'ACTIVA'",
        ["{$a}-{$m}-01", "{$a}-{$m}-01"]);
});

it('el XML del período trae la raíz con los atributos del manual (RifAgente del negocio y Periodo AAAAMM)', function () {
    [$a, $m] = mesActual();
    $id = registrarRetencion('N', 'HONORARIOS', null, "{$a}-{$m}-15");
    $xml = xmlMes($a, $m);

    expect($xml)->not->toBeNull()
        ->and(str_contains($xml, '<?xml version="1.0" encoding="utf-8"?>'))->toBeTrue();
    preg_match('/<RelacionRetencionesISLR RifAgente="([A-Za-z]\d{9})" Periodo="(\d{6})">/', $xml, $raiz);
    $rifNegocio = str_replace('-', '', DB::table('negocio')->value('rif'));
    expect($raiz)->toHaveCount(3)
        ->and($raiz[1])->toBe(strtoupper($rifNegocio))
        ->and($raiz[2])->toBe(sprintf('%04d%02d', $a, $m));
});

it('cada retención ACTIVA produce un DetalleRetencion con el orden estricto del xs:sequence', function () {
    [$a, $m] = mesActual();
    registrarRetencion('N', 'HONORARIOS', null, "{$a}-{$m}-05");
    $xml = xmlMes($a, $m);

    expect(preg_match_all('/<DetalleRetencion>/', $xml))->toBe(1)
        ->and(preg_match('/<DetalleRetencion><RifRetenido>V123456781<\/RifRetenido><NumeroFactura>0<\/NumeroFactura><NumeroControl>NA<\/NumeroControl><CodigoConcepto>002<\/CodigoConcepto><MontoOperacion>\d+\.\d{2}<\/MontoOperacion><PorcentajeRetencion>3\.00<\/PorcentajeRetencion><\/DetalleRetencion>/', $xml))->toBe(1);
});

it('mapea el concepto al código del Anexo 6.1 según el tipo de beneficiario (N→002 honorarios, J→004)', function () {
    [$a, $m] = mesActual();
    registrarRetencion('N', 'HONORARIOS', null, "{$a}-{$m}-03");
    registrarRetencion('J', 'HONORARIOS', null, "{$a}-{$m}-04");
    $xml = xmlMes($a, $m);

    expect(str_contains(detalle($xml, 'V123456781'), '<CodigoConcepto>002</CodigoConcepto>'))->toBeTrue()
        ->and(str_contains(detalle($xml, 'J308554839'), '<CodigoConcepto>004</CodigoConcepto>'))->toBeTrue()
        ->and(str_contains(detalle($xml, 'J308554839'), '<PorcentajeRetencion>5.00</PorcentajeRetencion>'))->toBeTrue();
});

it('la factura de más de 10 caracteres se corta a los ÚLTIMOS diez y sin factura va «0» (regla del manual)', function () {
    [$a, $m] = mesActual();
    registrarRetencion('N', 'COMISIONES', 'ABCDEF12345678', "{$a}-{$m}-06");   // 14 alfanuméricos
    registrarRetencion('N', 'FLETES', 'FAC-1234/56', "{$a}-{$m}-07");              // alfanuméricos con guiones
    $xml = xmlMes($a, $m);

    expect(str_contains(detalle($xml, 'V123456781'), '<NumeroFactura>EF12345678</NumeroFactura>'))->toBeTrue();
    expect(preg_match_all('/<NumeroFactura>/', $xml))->toBe(2);
});

it('el número de control es SOLO el secuencial numérico (máx 8) y «NA» cuando no existe', function () {
    [$a, $m] = mesActual();
    // Factura única por corrida: la guardia de compras impide editar cabeceras.
    $factura = 'CTRL' . substr(md5(uniqid('', true)), 0, 8);
    DB::statement("INSERT INTO compras (proveedor_id, numero_factura, numero_control, fecha_factura, moneda, tasa_cambio, total_usd, usuario_id)
                   VALUES ((SELECT id FROM proveedores WHERE activo ORDER BY id LIMIT 1), ?, '0001-00001234', ?::date, 'USD', 1, 1000, ?)",
        [$factura, "{$a}-{$m}-01", adminId()]);
    registrarRetencion('N', 'CONTRATISTAS', $factura, "{$a}-{$m}-08");   // compra con control → 00001234
    registrarRetencion('N', 'ALQUILER_INMUEBLES', 'FACTURA-XYZ-9', "{$a}-{$m}-09"); // control inexistente → NA
    $xml = xmlMes($a, $m);

    expect(str_contains($xml, '<NumeroControl>00001234</NumeroControl>'))->toBeTrue()
        ->and(str_contains($xml, '<NumeroControl>NA</NumeroControl>'))->toBeTrue();
});

it('los conceptos con sustraendo retienen la cifra neta que reporta el XML (002 · Decreto 1.808)', function () {
    [$a, $m] = mesActual();
    registrarRetencion('N', 'HONORARIOS', null, "{$a}-{$m}-10", 10000); // 10.000 × 3 % = 300 − 107,50 (43 UT × 83,3334 × 3 %)
    $xml = xmlMes($a, $m);
    $d = detalle($xml, 'V123456781');

    expect(str_contains($d, '<MontoOperacion>10000.00</MontoOperacion>'))->toBeTrue();
    preg_match('/<PorcentajeRetencion>([\d.]+)<\/PorcentajeRetencion>/', $d, $pct);
    $retenido = (float) DB::scalar("SELECT retenido_ves FROM retenciones_islr WHERE rif = 'V123456781' AND estado = 'ACTIVA' ORDER BY id DESC LIMIT 1");
    expect((float) $pct[1])->toBe(3.0)
        ->and(round(10000 * 0.03 - 107.5, 2))->toBe(round($retenido, 2));
});

it('las retenciones ANULADAS no van al archivo y los otros meses no se mezclan', function () {
    [$a, $m] = mesActual();
    $prev = (int) $m === 1 ? [$a - 1, 12] : [$a, $m - 1];
    $otro = registrarRetencion('N', 'COMISIONES', null, sprintf('%04d-%02d-05', $prev[0], $prev[1]));
    $quedan = registrarRetencion('N', 'FLETES', null, "{$a}-{$m}-11");
    $anulo = registrarRetencion('J', 'ALQUILER_MUEBLES', null, "{$a}-{$m}-12");
    DB::statement('SELECT fn_anular_retencion_islr(?::bigint, ?::text, ?::bigint)', [$anulo, 'Test XML: anulación', adminId()]);
    $xml = xmlMes($a, $m);

    expect($xml)->not->toBeNull()
        ->and(str_contains($xml, 'V123456781'))->toBeTrue()
        ->and(str_contains($xml, 'J308554839'))->toBeFalse()
        ->and(preg_match_all('/<DetalleRetencion>/', $xml))->toBe(1);
    preg_match('/Periodo="(\d{6})"/', $xml, $per);
    expect($per[1])->toBe(sprintf('%04d%02d', $a, $m))
        ->and(xmlMes($prev[0], $prev[1]))->toContain('V123456781')
        ->and((int) DB::scalar('SELECT count(*) FROM retenciones_islr WHERE id = ?', [$otro]))->toBe(1);
});

it('un período sin retenciones devuelve NULL y el endpoint lo rechaza con 422 (el Portal exige al menos un registro)', function () {
    [$a, $m] = mesActual();
    $otro = (int) $m === 12 ? [$a + 1, 1] : [$a, $m + 1];

    expect(xmlMes($otro[0], $otro[1]))->toBeNull()
        ->and($this->getJson('/retenciones-islr/xml/' . sprintf('%04d-%02d', $otro[0], $otro[1]))->status())->toBe(422);
});

it('el endpoint descarga el XML con el nombre oficial y rechaza períodos mal formados', function () {
    [$a, $m] = mesActual();
    registrarRetencion('N', 'HONORARIOS', null, "{$a}-{$m}-12");
    $rif = str_replace('-', '', DB::table('negocio')->value('rif'));

    $r = $this->get('/retenciones-islr/xml/' . sprintf('%04d-%02d', $a, $m));
    expect($r->status())->toBe(200)
        ->and($r->headers->get('Content-Type'))->toContain('text/xml')
        ->and($r->headers->get('Content-Disposition'))->toContain(sprintf('retenciones_islr_%04d%02d_%s.xml', $a, $m, strtoupper($rif)))
        // El patrón de la ruta (\d{4}-(0[1-9]|1[0-2])) rechaza períodos mal formados con 404.
        ->and($this->get('/retenciones-islr/xml/2026-13')->status())->toBe(404)
        ->and($this->get('/retenciones-islr/xml/NOV-2026')->status())->toBe(404);
});

it('un RIF de beneficiario inválido impide generar el archivo con un mensaje claro', function () {
    [$a, $m] = mesActual();
    $id = registrarRetencion('N', 'HONORARIOS', null, "{$a}-{$m}-13");
    // La guardia prohíbe editar retenciones: el dato corrupto se inyecta deshabilitando
    // el trigger SOLO para la prueba (el usuario lo corrige anulando y re-registrando).
    DB::statement('ALTER TABLE retenciones_islr DISABLE TRIGGER trg_retenciones_islr_guardia');
    DB::statement('UPDATE retenciones_islr SET rif = ?::text WHERE id = ?', ['V123', $id]);
    DB::statement('ALTER TABLE retenciones_islr ENABLE TRIGGER trg_retenciones_islr_guardia');

    try {
        xmlMes($a, $m);
        $this->fail('El XML no debe generarse con un RIF inválido.');
    } catch (Throwable $e) {
        $numero = DB::scalar('SELECT numero FROM retenciones_islr WHERE id = ?', [$id]);
        expect(str_contains($e->getMessage(), 'RIF de beneficiario'))->toBeTrue()
            ->and(str_contains($e->getMessage(), $numero))->toBeTrue();
    }
});
