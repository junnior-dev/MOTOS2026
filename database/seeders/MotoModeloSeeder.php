<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Modelos de moto más comunes en Venezuela (los que más se ven en talleres y repuesteras), para que
 * «qué repuesto le sirve a esta moto» tenga catálogo desde el día uno. Idempotente: lo que ya existe no se toca.
 *     php artisan db:seed --class=MotoModeloSeeder
 *
 * [marca, modelo, cilindrada] — la cilindrada es la comercial del modelo (para buscar/filtrar; si tu modelo
 * difiere, edítalo en el catálogo). Las marcas de MOTOS van aquí, no en la tabla marcas (que es de repuestos).
 */
class MotoModeloSeeder extends Seeder
{
    private const MODELOS = [
        // Bera (ensambladora venezolana, las 150 más vendidas del país)
        ['Bera', 'SBR 150', 150], ['Bera', 'KAVAK 150', 150], ['Bera', 'BRF 150', 150],
        // Empire Keeway (la ensambladora más grande de Venezuela)
        ['Empire Keeway', 'RKS 150', 150], ['Empire Keeway', 'Superlight 150', 150], ['Empire Keeway', 'Express', 150],
        // Honda
        ['Honda', 'CG 150 Titan', 150], ['Honda', 'XR 150 L', 150], ['Honda', 'CB 125 F', 125],
        ['Honda', 'Biz 125', 125], ['Honda', 'Navi', 110],
        // Yamaha
        ['Yamaha', 'FZ 16', 150], ['Yamaha', 'YBR 125', 125], ['Yamaha', 'Crypton T110', 110],
        ['Yamaha', 'XTZ 125', 125], ['Yamaha', 'Cygnus Z', 125],
        // Suzuki y AKT
        ['Suzuki', 'Ax 100', 100], ['Suzuki', 'GD 110 Hu', 110],
        ['AKT', 'NKD 125', 125],
    ];

    public function run(): void
    {
        foreach (self::MODELOS as [$marca, $modelo, $cc]) {
            DB::insert('INSERT INTO motos_modelos (marca, modelo, cilindrada) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                [$marca, $modelo, $cc]);
        }
        $this->command?->info('Modelos de moto asegurados (' . count(self::MODELOS) . ').');
    }
}
