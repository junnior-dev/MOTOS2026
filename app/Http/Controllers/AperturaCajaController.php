<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Turnos de caja (database/sql/14_aperturas_caja.sql). Controlador delgado: la apertura, el cierre y las cifras del turno
 * las hacen fn_abrir_caja, fn_cerrar_apertura y fn_resumen_apertura en PostgreSQL (con bloqueo consultivo por usuario).
 * Sin turno abierto el POS no vende (lo exige CajaController::registrarDatos).
 */
class AperturaCajaController extends Controller
{
    /** Turno abierto del usuario como arreglo (o null). Lo usa también el POS al cargar. */
    public function activa(?int $usuarioId = null): ?array
    {
        $a = DB::selectOne("SELECT a.id, a.fecha_apertura, a.fondo_inicial_usd, a.fondo_inicial_ves, a.fondo_inicial_moneda, a.fondo_inicial_eur, a.fondo_inicial_cop
                             FROM aperturas_caja a WHERE a.usuario_id = ? AND a.estado = 'ABIERTA'", [$usuarioId ?? auth()->id()]);
        return $a ? ['id' => (int) $a->id, 'fecha_apertura' => $a->fecha_apertura,
            'fondo_usd' => (float) $a->fondo_inicial_usd, 'fondo_ves' => (float) $a->fondo_inicial_ves,
            'fondo_moneda' => $a->fondo_inicial_moneda, 'fondo_eur' => (float) $a->fondo_inicial_eur, 'fondo_cop' => (float) $a->fondo_inicial_cop] : null;
    }

    /** GET /caja/apertura → turno activo o null. */
    public function estadoActual()
    {
        return response()->json(['apertura' => $this->activa()]);
    }

    /** POST /caja/apertura {fondo_moneda, fondo_monto} — o la forma antigua {fondo_inicial_usd, fondo_inicial_ves}. */
    public function store(Request $r)
    {
        $d = $r->validate([
            'fondo_moneda' => 'nullable|in:USD,VES,EUR,COP',
            'fondo_monto' => 'nullable|numeric|min:0|max:999999999999',
            'fondo_inicial_usd' => 'nullable|numeric|min:0|max:999999999',
            'fondo_inicial_ves' => 'nullable|numeric|min:0|max:999999999999',
        ], [
            'fondo_moneda.in' => 'La moneda del fondo debe ser USD, Bs, EUR o COP.',
            'fondo_monto.min' => 'El fondo no puede ser negativo.',
            'fondo_inicial_usd.min' => 'El fondo no puede ser negativo.',
            'fondo_inicial_ves.min' => 'El fondo no puede ser negativo.',
        ]);
        // Tasas vigentes (Bs por unidad de cada moneda). Sin tasas del día no se puede convertir el fondo.
        $t = DB::table('v_tasas_actuales')->where('moneda_destino', 'VES')->whereIn('moneda_origen', ['USD', 'EUR', 'COP'])->pluck('tasa', 'moneda_origen');
        if ($t->isEmpty() || (float) ($t['USD'] ?? 0) <= 0 || (float) ($t['EUR'] ?? 0) <= 0 || (float) ($t['COP'] ?? 0) <= 0) {
            return response()->json(['message' => 'Faltan las tasas del día para USD, EUR o COP. Regístralas en «Tasas de cambio» antes de abrir la caja.'], 422);
        }
        $T = ['VES' => 1.0, 'USD' => (float) $t['USD'], 'EUR' => (float) $t['EUR'], 'COP' => (float) $t['COP']];

        if ($r->filled('fondo_moneda')) {
            // Fondo contado en una sola moneda: se convierte a las cuatro con las tasas del día.
            $moneda = $d['fondo_moneda'] ?? 'USD';
            $monto = (float) ($d['fondo_monto'] ?? 0);
            $enUsd = $monto * $T[$moneda] / $T['USD'];
            $fondos = [
                'USD' => $enUsd,
                'VES' => $monto * $T[$moneda],
                'EUR' => $enUsd * $T['USD'] / $T['EUR'],
                'COP' => $enUsd * $T['USD'] / $T['COP'],
            ];
        } else {
            // Forma antigua: dos montos independientes (USD y Bs); EUR y COP quedan convertidos desde el fondo en USD.
            $moneda = ((float) ($d['fondo_inicial_usd'] ?? 0)) > 0 ? 'USD' : 'VES';
            $usd = (float) ($d['fondo_inicial_usd'] ?? 0);
            $fondos = ['USD' => $usd, 'VES' => (float) ($d['fondo_inicial_ves'] ?? 0),
                'EUR' => $usd * $T['USD'] / $T['EUR'], 'COP' => $usd * $T['USD'] / $T['COP']];
        }
        try {
            DB::scalar('SELECT fn_abrir_caja(?, ?, ?, ?, ?, ?)', [auth()->id(), $fondos['USD'], $fondos['VES'], $fondos['EUR'], $fondos['COP'], $moneda]);
        } catch (QueryException $e) {
            return response()->json(['message' => $this->mensaje($e, 'No se pudo abrir la caja.')], 422);
        }
        return response()->json(['apertura' => $this->activa()]);
    }

    /**
     * Marca el turno como CERRADO. No es una ruta suelta: la llama CierreCajaController::store dentro de su transacción,
     * después de guardar el conteo, para que no se pueda cerrar un turno sin contar el efectivo.
     */
    public function cerrar(int $aperturaId): void
    {
        DB::select('SELECT fn_cerrar_apertura(?, ?)', [$aperturaId, auth()->id()]);
    }

    /** GET /caja/corte-x → resumen parcial del turno abierto (no cierra nada). */
    public function corteX()
    {
        $a = $this->activa();
        if (! $a) {
            return response()->json(['message' => 'No tienes una caja abierta.'], 422);
        }
        return response()->json($this->resumen($a['id']));
    }

    /** Resumen del turno (Corte X / Z) con los datos del emisor para el ticket. */
    public function resumen(int $aperturaId): array
    {
        $r = json_decode(DB::scalar('SELECT fn_resumen_apertura(?)::text', [$aperturaId]), true);
        $n = DB::table('negocio')->first(['razon_social', 'nombre_comercial', 'rif', 'direccion_fiscal']);
        $r['emisor'] = ['razon_social' => $n->razon_social, 'comercial' => $n->nombre_comercial, 'rif' => $n->rif, 'direccion' => $n->direccion_fiscal];
        return $r;
    }

    private function mensaje(QueryException $e, string $porDefecto): string
    {
        if ($e->getCode() === '23505') {
            return 'Ya tienes una caja abierta.';
        }
        return preg_match('/ERROR:\s*(.+?)(?:\s*CONTEXT:|\n|\(Connection|$)/su', $e->getMessage(), $m) ? trim($m[1]) : $porDefecto;
    }
}
