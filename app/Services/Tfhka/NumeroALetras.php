<?php

namespace App\Services\Tfhka;

/**
 * Monto en letras para el campo «MontoEnLetras» que exige TFHKA.
 * Español de Venezuela, minúsculas y sin depender de intl.
 */
final class NumeroALetras
{
    private const UNIDADES = ['cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

    private const DECENAS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const CENTENAS = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    /** «ciento dieciséis bolívares con cero céntimos» */
    public static function bolivares(float $monto): string
    {
        $entero = (int) floor(round($monto, 2));
        $centimos = (int) round((round($monto, 2) - $entero) * 100);
        if ($centimos === 100) {
            $entero++;
            $centimos = 0;
        }

        return self::entero($entero).' '.($entero === 1 ? 'bolívar' : 'bolívares')
            .' con '.self::entero($centimos).' céntimos';
    }

    /** «un dólar con veinticinco centavos» */
    public static function moneda(float $monto, string $singular, string $plural, string $centimo = 'centavos'): string
    {
        $entero = (int) floor(round($monto, 2));
        $centimos = (int) round((round($monto, 2) - $entero) * 100);
        if ($centimos === 100) {
            $entero++;
            $centimos = 0;
        }

        return self::entero($entero).' '.($entero === 1 ? $singular : $plural)
            .' con '.self::entero($centimos).' '.$centimo;
    }

    public static function entero(int $n): string
    {
        if ($n === 0) {
            return 'cero';
        }
        if ($n < 0) {
            return 'menos '.self::entero(-$n);
        }
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;

            return $u === 0 ? self::DECENAS[$d] : self::DECENAS[$d].' y '.self::UNIDADES[$u];
        }
        if ($n < 1000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            if ($n === 100) {
                return 'cien';
            }

            return self::CENTENAS[$c].($r > 0 ? ' '.self::entero($r) : '');
        }
        if ($n < 1_000_000) {
            $miles = intdiv($n, 1000);
            $r = $n % 1000;
            $txt = $miles === 1 ? 'mil' : self::entero($miles).' mil';

            return $txt.($r > 0 ? ' '.self::entero($r) : '');
        }
        if ($n < 1_000_000_000) {
            $millones = intdiv($n, 1_000_000);
            $r = $n % 1_000_000;
            $txt = $millones === 1 ? 'un millón' : self::entero($millones).' millones';

            return $txt.($r > 0 ? ' '.self::entero($r) : '');
        }

        $milesMillones = intdiv($n, 1_000_000_000);
        $r = $n % 1_000_000_000;
        $txt = $milesMillones === 1 ? 'mil millones' : self::entero($milesMillones).' mil millones';

        return $txt.($r > 0 ? ' '.self::entero($r) : '');
    }
}