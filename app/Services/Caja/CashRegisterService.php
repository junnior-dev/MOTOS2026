<?php

namespace App\Services\Caja;

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CashRegisterService · cálculo exacto del arqueo multimoneda (USD, VES, EUR, COP).
 *
 * NO recalcula nada: la regla contable vive en la base de datos (fn_resumen_apertura,
 * SQL 16/24), que por cada moneda devuelve
 *     esperado = fondo inicial + cobros en efectivo − vueltos (por vuelto_moneda)
 *                + ingresos en efectivo − egresos en efectivo
 * con NUMERIC exacto, sin mezclar divisas y con los vueltos cruzados aplicados a la
 * moneda en que se entregaron físicamente. Este servicio la envuelve y añade:
 *   · la cuadratura del conteo con bcmath y la precisión de cada moneda (monedas.decimales),
 *   · la regla «la nota del cierre es obligatoria si CUALQUIER moneda descuadra»,
 *   · el desglose bancario (pagos que no van a la gaveta) y las tasas congeladas del snapshot.
 * El cierre se firma en CierreCajaController dentro de una transacción con FOR UPDATE.
 */
class CashRegisterService
{
    /** Resumen exacto del turno, tal como lo calcula la base (fn_resumen_apertura). */
    public function resumenTurno(int $aperturaId): array
    {
        return json_decode((string) DB::scalar('SELECT fn_resumen_apertura(?)::text', [$aperturaId]), true);
    }

    /** Precisión de conteo por moneda (monedas.decimales: COP 0, resto 2). */
    public function decimalesPorMoneda(): array
    {
        $out = [];
        foreach (DB::select('SELECT trim(codigo) AS moneda, decimales FROM monedas') as $m) {
            $out[$m->moneda] = (int) $m->decimales;
        }
        return $out;
    }

    /**
     * Cuadra el efectivo contado contra el esperado, moneda por moneda (cero mezclas).
     * Devuelve [esperado, conteo, diferencia] como strings exactos de bcmath.
     * Reglas: USD y VES siempre se cuentan; EUR y COP solo cuando tuvieron efectivo
     * esperado o recibido en el turno. Un conteo faltante es un error del arqueo.
     */
    public function cuadrarEfectivo(array $resumen, array $conteoEnviado, array $decimales): array
    {
        $esperado = $conteo = $dif = [];
        foreach ($resumen['monedas'] as $m) {
            $moneda = trim($m['moneda']);
            $dec = $decimales[$moneda] ?? 2;
            if (! in_array($moneda, ['USD', 'VES'], true)
                && Money::esCero((string) $m['esperado']) && Money::esCero((string) $m['efectivo_recibido'])) {
                continue; // moneda sin actividad en el turno: no se exige contarla
            }
            $esperado[$moneda] = Money::redondear($m['esperado'], $dec);
            $v = $conteoEnviado[$moneda] ?? null;
            if ($v === null || $v === '') {
                throw ValidationException::withMessages(['conteo' => "Cuenta el efectivo en {$moneda} antes de cerrar (puede ser 0)."]);
            }
            if ((float) $v < 0) {
                throw ValidationException::withMessages(['conteo' => "El conteo en {$moneda} no puede ser negativo."]);
            }
            $conteo[$moneda] = Money::redondear($v, $dec);
            $dif[$moneda] = Money::restar($conteo[$moneda], $esperado[$moneda], $dec);
        }
        return [$esperado, $conteo, $dif];
    }

    /** ¿Descuadró alguna moneda? (diferencia distinta de cero más allá de la tolerancia). */
    public function hayDescuadre(array $dif): bool
    {
        foreach ($dif as $v) {
            if (! Money::esCero($v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nota del cierre OBLIGATORIA si cualquiera de las monedas descuadra (regla de
     * auditoría: cada faltante o sobrante debe quedar explicado en el historial).
     */
    public function exigirNotaSiDescuadra(array $dif, ?string $nota): void
    {
        if ($this->hayDescuadre($dif) && trim((string) $nota) === '') {
            $malas = [];
            foreach ($dif as $moneda => $v) {
                if (! Money::esCero($v)) {
                    $malas[] = $moneda;
                }
            }
            throw ValidationException::withMessages(['observaciones' => 'Hay diferencias en ' . implode(', ', $malas) . ': escribe la nota del cierre explicándolas antes de continuar.']);
        }
    }

    /** Pagos que NO van a la gaveta (banco): por método y moneda nativa, con su conteo. */
    public function bancoPorMetodo(array $resumen): array
    {
        $out = [];
        foreach ($resumen['metodos'] ?? [] as $m) {
            if (! empty($m['efectivo']) || (int) $m['n'] === 0) {
                continue;
            }
            $out[] = ['metodo' => $m['metodo'], 'moneda' => trim($m['moneda']), 'n' => (int) $m['n'],
                'cobrado' => Money::redondear($m['cobrado'], $this->decimalesPorMoneda()[trim($m['moneda'])] ?? 2),
                'usd' => Money::redondear($m['usd'], 2), 'ves' => Money::redondear($m['ves'], 2)];
        }
        return $out;
    }

    /** Tasas vigentes del día (BCV / fuente activa) para congelar en el snapshot del cierre. */
    public function tasasVigentes(): array
    {
        $out = [];
        foreach (DB::select("SELECT moneda_origen AS moneda, tasa, fuente, fecha_valor::text AS fecha
                              FROM v_tasas_actuales WHERE moneda_destino = 'VES' AND moneda_origen IN ('USD','EUR','COP')") as $t) {
            $out[trim($t->moneda)] = ['tasa' => (float) $t->tasa, 'fuente' => $t->fuente, 'fecha' => $t->fecha];
        }
        return $out;
    }
}
