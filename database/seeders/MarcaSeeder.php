<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Marcas de REPUESTOS más usadas en el rubro (NGK, Bendix, Motul...). Las marcas de MOTOS no van aquí:
 * se escriben en motos_modelos.marca (MotoModeloSeeder). Idempotente: lo que ya existe no se toca.
 *     php artisan db:seed --class=MarcaSeeder
 */
class MarcaSeeder extends Seeder
{
    private const MARCAS = [
        'NGK', 'Denso', 'Bendix', 'Ferodo', 'Nissin', 'Brembo',       // bujías y frenos
        'Motul', 'Castrol', 'Shell', 'Mobil', 'Lubrax',               // lubricantes
        'Pirelli', 'Michelin', 'Kenda', 'CST',                        // cauchos
        'DID', 'RK',                                                  // cadenas
        'Yuasa',                                                      // baterías
    ];

    public function run(): void
    {
        foreach (self::MARCAS as $nombre) {
            DB::insert('INSERT INTO marcas (nombre) VALUES (?) ON CONFLICT DO NOTHING', [$nombre]);
        }
        $this->command?->info('Marcas de repuestos aseguradas (' . count(self::MARCAS) . ').');
    }
}
