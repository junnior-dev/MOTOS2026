<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza el HISTORIAL REAL de la unidad tributaria en «Retenciones ISLR → Parámetros de ISLR».
 *     php artisan db:seed --class=UnidadTributariaSeeder   (o db:seed a secas: DatabaseSeeder lo llama)
 *
 * Historial verificado (la UT es dato público; no se siembra ficción):
 *   Bs  9,00 desde 08/05/2023 · Providencia SNAT/2023/000031, G.O. n.° 42.623 del 08/05/2023
 *   Bs 43,00 desde 02/06/2025 · Providencia SNAT/2025/000048, G.O. n.° 43.140 del 02/06/2025 — VIGENTE
 *
 * Escribe con INSERT … ON CONFLICT (desde) DO UPDATE (no vía fn_islr_guardar_ut) a propósito: esa función es
 * para registrar REAJUSTES nuevos desde la pantalla y se niega si ya hay retenciones desde la fecha — en una
 * instalación con retenciones registradas, un seeder histórico debe poder correr igual. No hay riesgo: cada
 * retención congeló su UT al registrarse (columna ut_ves), así que respaldar el historial no la afecta.
 * Cuando el SENIAT publique un nuevo reajuste, agrégalo desde la pantalla (o agrega aquí otra fila con su fecha).
 */
class UnidadTributariaSeeder extends Seeder
{
    /** [desde, valor, fuente] — de más vieja a más nueva. */
    public const HISTORIAL = [
        ['2023-05-08', 9.00, 'UT Bs 9,00 (Providencia SNAT/2023/000031, G.O. n.° 42.623)'],
        ['2025-06-02', 43.00, 'UT Bs 43,00 (Providencia SNAT/2025/000048, G.O. n.° 43.140)'],
    ];

    public function run(): void
    {
        $creadas = 0;
        foreach (self::HISTORIAL as [$desde, $valor, $nota]) {
            $creadas += DB::affectingStatement(
                'INSERT INTO islr_ut (desde, valor_ves, nota, usuario_id) VALUES (?::date, ?, ?, 1)
                 ON CONFLICT (desde) DO UPDATE SET valor_ves = EXCLUDED.valor_ves, nota = EXCLUDED.nota',
                [$desde, $valor, $nota]);
        }
        $vigente = DB::scalar('SELECT fn_islr_ut(fn_hoy())');
        $this->command?->info("UT asegurada ($creadas filas escritas); vigente hoy: Bs " . number_format((float) $vigente, 2, ',', '.'));
    }
}
