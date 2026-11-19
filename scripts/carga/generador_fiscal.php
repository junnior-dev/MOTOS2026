<?php
/**
 * generador_fiscal.php · datos SIMULADOS con fakerphp/faker (locale es_VE) para la
 * suite t20 de validación fiscal. No toca la base: escribe /tmp/datos_fiscales.json
 * y el driver Python decide cómo registrarlos.
 *
 * Uso: php scripts/carga/generador_fiscal.php [--ventas N] [--compras N]
 */

require __DIR__ . '/../../vendor/autoload.php';

use Faker\Factory;

$faker = Factory::create('es_VE');

$nVentas = (int) (getopt('', ['ventas:'])['ventas'] ?? 160);
$nCompras = (int) (getopt('', ['compras:'])['compras'] ?? 45);

// ── clientes (personas y empresas con RIF real por dígito de control) ─────────
function rif(string $tipo): string
{
    $cuerpo = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $v = ['V' => 1, 'E' => 2, 'J' => 3, 'P' => 4, 'G' => 5, 'C' => 3][$tipo];
    $pesos = [3, 2, 7, 6, 5, 4, 3, 2];
    $suma = $v * 4;
    foreach (str_split($cuerpo) as $i => $d) {
        $suma += (int) $d * $pesos[$i];
    }
    $dv = 11 - ($suma % 11);
    return $tipo . '-' . $cuerpo . '-' . ($dv >= 10 ? 0 : $dv);
}

$clientes = [];
for ($i = 0; $i < 25; $i++) {
    $naturales = $i < 15;
    $clientes[] = [
        'tipo' => $naturales ? 'V' : 'J',
        'documento' => $naturales ? (string) $faker->numberBetween(10000000, 29999999) : substr(rif('J'), 2, 8),
        'nombre' => $naturales ? $faker->name : $faker->company,
        'rif' => $naturales ? rif('V') : rif('J'),
        'telefono' => $faker->numerify('0414-#######'),
        'correo' => $faker->safeEmail,
    ];
}

// ── proveedores ───────────────────────────────────────────────────────────────
$proveedores = [];
for ($i = 0; $i < 12; $i++) {
    $proveedores[] = [
        'razon_social' => $faker->company . ' C.A.',
        'rif' => rif('J'),
        'telefono' => $faker->numerify('0251-#######'),
        'correo' => $faker->companyEmail,
    ];
}

// ── productos extra (repuestos de moto, con código de barras EAN-13 válido) ───
$nombres = ['Faro LED', 'Kit de arrastre', 'Disco de freno', 'Amortiguador trasero', 'Regulador de voltaje',
    'Bobina de encendido', 'Carburador', 'Cámara de aire', 'Maneral de acelerador', 'Cable de embrague',
    'Filtro de aire', 'Bomba de gasolina', 'Piñón de salida', 'Corona', 'Guaya de freno', 'Espejo deportivo',
    'Casco visor', 'Guantes', 'Aceite de suspensión', 'Grasa de chasis', 'Bujía iridio', 'Bobina CDI',
    'Relé de arranque', 'Interruptor de luz', 'Portaequipaje', 'Parrilla', 'Cadena reforzada', 'Tensador',
    'Sillin', 'Tapa de tanque'];
$productos = [];
for ($i = 0; $i < count($nombres); $i++) {
    $costo = $faker->randomFloat(2, 1.2, 45);
    $productos[] = [
        'sku' => 'FK-' . strtoupper($faker->lexify('?????')) . '-' . ($i + 1),
        'nombre' => $nombres[$i] . ' ' . $faker->randomElement(['universal', 'Honda', 'Yamaha', 'Bera', 'Suzuki']),
        'costo_usd' => $costo,
        'margen_pct' => $faker->randomElement([20, 25, 30, 35, 40]),
        'exento_iva' => $faker->boolean(8),          // ~8 % de productos exentos (medicamentos/lubricantes especiales)
        'exonerado_iva' => $faker->boolean(4),
        'barcode' => '770' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT) . '0',
        'stock' => $faker->numberBetween(40, 160),
    ];
}

// ── ventas: mezcla realista de métodos, descuentos y crédito ─────────────────
$ventas = [];
for ($i = 0; $i < $nVentas; $i++) {
    $nItems = $faker->numberBetween(1, 4);
    $items = [];
    for ($j = 0; $j < $nItems; $j++) {
        $items[] = ['producto_idx' => $faker->numberBetween(0, count($productos) - 1),
                    'cantidad' => $faker->numberBetween(1, 5),
                    'descuento_pct' => $faker->randomElement([0, 0, 0, 5, 10, 15])];
    }
    $rifa = $faker->randomFloat(2, 0, 100);
    if ($rifa < 32) $pago = ['modo' => 'bs'];
    elseif ($rifa < 50) $pago = ['modo' => 'divisa'];
    elseif ($rifa < 62) $pago = ['modo' => 'mixto', 'parte_usd' => $faker->randomFloat(2, 0.2, 0.8)];
    elseif ($rifa < 70) $pago = ['modo' => 'credito', 'dias' => $faker->randomElement([8, 15, 30, 45, 60])];
    else $pago = ['modo' => 'bs'];
    $ventas[] = [
        'items' => $items,
        'cliente_idx' => $faker->boolean(55) ? $faker->numberBetween(0, count($clientes) - 1) : null,
        'pago' => $pago,
        'anular' => $faker->boolean(8),
        'motivo_anulacion' => $faker->randomElement(['Cliente desistió', 'Error de captura del cajero', 'No tenía el repuesto en tienda']),
        'observaciones' => 'Faker #' . ($i + 1),
    ];
}

// ── compras ───────────────────────────────────────────────────────────────────
$compras = [];
for ($i = 0; $i < $nCompras; $i++) {
    $nItems = $faker->numberBetween(1, 4);
    $items = [];
    for ($j = 0; $j < $nItems; $j++) {
        $items[] = ['producto_idx' => $faker->numberBetween(0, count($productos) - 1),
                    'cantidad' => $faker->numberBetween(5, 40),
                    'costo_unitario' => $faker->randomFloat(2, 1, 40),
                    'exento' => $faker->boolean(10)];
    }
    $compras[] = [
        'proveedor_idx' => $faker->numberBetween(0, count($proveedores) - 1),
        'numero_factura' => $faker->numerify('FC-######'),
        'numero_control' => $faker->numerify('CTRL-######'),
        'items' => $items,
        'aplica_credito_fiscal' => $faker->boolean(62),
        'anular' => $faker->boolean(6),
        'motivo_anulacion' => 'Mercancía devuelta al proveedor',
    ];
}

// ── retenciones de IVA sobre compras con crédito fiscal ──────────────────────
$retenciones_iva = [];
foreach ($compras as $idx => $c) {
    if ($c['aplica_credito_fiscal'] && $faker->boolean(50)) {
        $retenciones_iva[] = ['compra_idx' => $idx, 'porcentaje' => $faker->boolean(85) ? 75 : 100];
    }
}

// ── retenciones de ISLR (Decreto 1808) ───────────────────────────────────────
$islr = [];
foreach (['HONORARIOS', 'COMISIONES', 'CONTRATISTAS', 'ALQUILER_INMUEBLES', 'ALQUILER_MUEBLES', 'FLETES'] as $concepto) {
    for ($i = 0; $i < 3; $i++) {
        $esNatural = $faker->boolean(50);
        $islr[] = [
            'beneficiario' => $esNatural ? $faker->name : $faker->company . ' C.A.',
            'rif' => $esNatural ? rif('V') : rif('J'),
            'tipo' => $esNatural ? 'N' : 'J',
            'concepto' => $concepto,
            'base_ves' => $faker->randomFloat(2, 4200, 26000),
            'fondos' => $faker->randomElement(['CAJA', 'BANCOS']),
        ];
    }
}

file_put_contents('/tmp/datos_fiscales.json', json_encode([
    'clientes' => $clientes, 'proveedores' => $proveedores, 'productos' => $productos,
    'ventas' => $ventas, 'compras' => $compras, 'retenciones_iva' => $retenciones_iva, 'islr' => $islr,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "datos generados: " . count($ventas) . " ventas · " . count($compras) . " compras · "
    . count($retenciones_iva) . " ret. IVA · " . count($islr) . " ret. ISLR\n";
