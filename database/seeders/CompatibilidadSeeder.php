<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Compatibilidad producto ↔ modelo de moto para los PRODUCTOS DEMO del sistema (M2-*).
 * Sirve de ejemplo del mecanismo: en una instalación real, cada repuesto que registres lleva sus modelos.
 *     php artisan db:seed --class=CompatibilidadSeeder
 *
 * sku => [años [desde, hasta] (NULL = todos los años), modelos: '*' = todos, o lista [marca, modelo]]
 * Mapeo razonable y editable (el aceite 4T a todos los de 4 tiempos, la bujía a los 110-125, las
 * pastillas a los que traen disco delantero, el caucho 2.75-17 a los de rueda chica...). Idempotente.
 */
class CompatibilidadSeeder extends Seeder
{
    private const COMPAT = [
        'M2-ACEITE'   => [null, [['Honda', 'CG 150 Titan'], ['Honda', 'XR 150 L'], ['Honda', 'CB 125 F'], ['Honda', 'Biz 125'], ['Honda', 'Navi'], ['Yamaha', 'FZ 16'], ['Yamaha', 'YBR 125'], ['Yamaha', 'Crypton T110'], ['Yamaha', 'XTZ 125'], ['Yamaha', 'Cygnus Z'], ['Suzuki', 'GD 110 Hu'], ['AKT', 'NKD 125'], ['Bera', 'SBR 150'], ['Bera', 'KAVAK 150'], ['Bera', 'BRF 150'], ['Empire Keeway', 'RKS 150'], ['Empire Keeway', 'Superlight 150'], ['Empire Keeway', 'Express']]],   // 20W-50 4T: todos los de 4 tiempos (el Ax 100 es 2T)
        'M2-BUJIA'    => [null, [['Yamaha', 'Crypton T110'], ['Yamaha', 'YBR 125'], ['Honda', 'Biz 125'], ['Honda', 'Navi'], ['Suzuki', 'GD 110 Hu'], ['AKT', 'NKD 125']]],
        'M2-FILTRO'   => [null, [['Honda', 'CG 150 Titan'], ['Honda', 'XR 150 L'], ['Yamaha', 'FZ 16'], ['Yamaha', 'XTZ 125'], ['Bera', 'SBR 150'], ['Bera', 'KAVAK 150'], ['Bera', 'BRF 150'], ['Empire Keeway', 'RKS 150'], ['Empire Keeway', 'Superlight 150'], ['Empire Keeway', 'Express']]],
        'M2-PASTILLA' => [[2010, null], [['Yamaha', 'FZ 16'], ['Yamaha', 'YBR 125'], ['Honda', 'CB 125 F'], ['Honda', 'XR 150 L'], ['Bera', 'SBR 150'], ['Bera', 'KAVAK 150'], ['Bera', 'BRF 150'], ['Empire Keeway', 'RKS 150'], ['Empire Keeway', 'Superlight 150']]],
        'M2-LLANTA'   => [[2000, null], [['Yamaha', 'Crypton T110'], ['Yamaha', 'YBR 125'], ['Honda', 'Biz 125'], ['Suzuki', 'GD 110 Hu'], ['Suzuki', 'Ax 100']]],
        'M2-CAMARA'   => [[2000, null], [['Yamaha', 'Crypton T110'], ['Yamaha', 'YBR 125'], ['Honda', 'Biz 125'], ['Suzuki', 'GD 110 Hu'], ['Suzuki', 'Ax 100']]],
        'M2-CADENA'   => [null, [['Yamaha', 'Crypton T110'], ['Yamaha', 'YBR 125'], ['Honda', 'Biz 125'], ['Honda', 'Navi'], ['Suzuki', 'GD 110 Hu'], ['AKT', 'NKD 125']]],
        'M2-ESPEJO'   => [null, '*'],   // universal
    ];

    public function run(): void
    {
        $creadas = 0;
        foreach (self::COMPAT as $sku => [$anios, $modelos]) {
            $producto = DB::selectOne('SELECT id FROM productos WHERE sku = ?', [$sku]);
            if (!$producto) {
                $this->command?->warn("$sku no existe en productos: su compatibilidad se omite.");
                continue;
            }
            $filas = $modelos === '*'
                ? DB::select('SELECT marca, modelo FROM motos_modelos')
                : array_map(fn (array $m) => (object) ['marca' => $m[0], 'modelo' => $m[1]], $modelos);
            foreach ($filas as $f) {
                $creadas += DB::affectingStatement(
                    "INSERT INTO producto_compatibilidad (producto_id, moto_modelo_id, anio_desde, anio_hasta)
                     SELECT ?, id, ?, ? FROM motos_modelos WHERE marca = ? AND modelo = ?
                     ON CONFLICT (producto_id, moto_modelo_id) DO NOTHING",
                    [$producto->id, $anios[0] ?? null, $anios[1] ?? null, $f->marca, $f->modelo]);
            }
        }
        $this->command?->info("Compatibilidad demo creada: $creadas filas nuevas.");
    }
}
