<?php

namespace App\Support;

/**
 * Money · aritmética exacta de dinero con bcmath (sin errores de coma flotante).
 *
 * La base de datos guarda TODO en NUMERIC(18,2) (NUMERIC(20,8) las tasas) y hace los
 * cálculos del arqueo en SQL numérico exacto. Este asistente cubre la parte PHP del
 * snapshot del cierre (redondeo del conteo y de la diferencia por moneda), respetando
 * la precisión configurada por moneda (monedas.decimales: COP = 0, resto = 2).
 *
 *   Money::redondear('122.7749')          → '122.77'
 *   Money::restar('120.00', '121.50')     → '-1.50'
 *   Money::esCero('-0.004')               → true  (tolerancia del medio centavo)
 */
final class Money
{
    /** Tolera un medio centavo: un descuadre menor que esto cuenta como caja cuadrada. */
    public const TOLERANCIA = '0.005';

    /** Convierte cualquier entrada a string decimal sin notación científica. */
    public static function normalizar(string|float|int $monto): string
    {
        return number_format((float) $monto, 6, '.', '');
    }

    /** Redondea con bcmath a la precisión de la moneda (2 por defecto, 0 para COP). */
    public static function redondear(string|float|int $monto, int $decimales = 2): string
    {
        return bcadd(self::normalizar($monto), '0', max(0, $decimales));
    }

    /** a − b con la precisión de la moneda. */
    public static function restar(string|float|int $a, string|float|int $b, int $decimales = 2): string
    {
        return bcsub(self::normalizar($a), self::normalizar($b), max(0, $decimales));
    }

    /** a + b con la precisión de la moneda. */
    public static function sumar(string|float|int $a, string|float|int $b, int $decimales = 2): string
    {
        return bcadd(self::normalizar($a), self::normalizar($b), max(0, $decimales));
    }

    /** Comparación exacta: devuelve -1, 0 o 1 (bccomp). */
    public static function comparar(string|float|int $a, string|float|int $b, int $decimales = 2): int
    {
        return bccomp(self::normalizar($a), self::normalizar($b), max(0, $decimales));
    }

    /** ¿La diferencia es prácticamente cero? (típicamente |dif| < 0.005 → «Cuadra»). */
    public static function esCero(string|float|int $dif): bool
    {
        return bccomp(str_replace('-', '', self::normalizar($dif)), self::TOLERANCIA, 6) === -1;
    }
}
