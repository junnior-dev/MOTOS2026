<?php

namespace Database\Seeders;
use Illuminate\Database\Seeder;
// La base se instala con los archivos SQL de database/sql (ver LEEME.md). No hay migraciones; estos
// seeders precargan lo configurable: `php artisan db:seed` los corre en orden (cada uno es idempotente).
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnidadTributariaSeeder::class,   // UT real Bs 43,00 desde 02/06/2025 (SNAT/2025/000048)
            MarcaSeeder::class,              // marcas de repuestos (NGK, Bendix, Motul...)
            MotoModeloSeeder::class,         // modelos de moto comunes en Venezuela
            CompatibilidadSeeder::class,     // compatibilidad demo producto ↔ modelo
        ]);
    }
}
