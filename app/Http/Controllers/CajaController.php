<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;
use App\Jobs\EmitirDocumentoFiscal;
use App\Services\Caja\CashRegisterService;
use App\Services\Tfhka\DatosDocumento;
use App\Services\Tfhka\EmisorFiscal;

/**
 * Punto de venta (/caja). No calcula nada fiscal por su cuenta: usa tus funciones de la base de datos.
 *   fn_cotizar_venta + fn_calcular_linea_venta → totales mientras se arma la venta
 *   fn_registrar_venta                         → guarda líneas, pagos mixtos, IGTF y descuenta stock en una transacción
 *   sp_asignar_factura_fiscal                  → fija el número de factura y de control emitidos por el medio de facturación
 * El precio, el costo, la tasa y las alícuotas los fija la base de datos; la pantalla solo envía producto, cantidad y descuento.
 * Los métodos de datos son públicos para poder probarlos sin HTTP.
 */
class CajaController extends Controller
{
    use ContextoPagina;

    public function index()
    {
        return Inertia::render('Caja/Index', [...$this->contexto(), ...$this->datosPagina()]);
    }

    /** GET /caja-en-vivo → pantalla «Cajas en vivo»: qué hay ahora mismo en cada caja abierta. */
    public function enVivo()
    {
        $filas = DB::select("SELECT a.id, a.usuario_id, a.fecha_apertura, u.nombre_completo
                              FROM aperturas_caja a JOIN usuarios u ON u.id = a.usuario_id
                             WHERE a.estado = 'ABIERTA' ORDER BY a.id");
        $cajas = [];
        $orden = ['USD' => 0, 'VES' => 1, 'EUR' => 2, 'COP' => 3];
        foreach ($filas as $i => $a) {
            $r = json_decode(DB::scalar('SELECT fn_resumen_apertura(?)::text', [$a->id]), true);
            $bancoUsd = $bancoVes = $bancoPagos = $gavetaUsd = $gavetaVes = 0.0;
            $bancoPorMoneda = [];
            foreach ($r['metodos'] as $m) {
                if (empty($m['efectivo'])) {
                    $bancoUsd += (float) $m['usd'];
                    $bancoVes += (float) $m['ves'];
                    $bancoPagos += (int) $m['n'];
                    $mon = trim($m['moneda']);
                    $bancoPorMoneda[$mon] ??= 0.0;
                    // Cada pago queda en SU moneda nativa: Bs en el método VES, $ en el USD, COP/EUR en la suya.
                    $bancoPorMoneda[$mon] += (float) ($mon === 'VES' ? $m['ves'] : ($mon === 'USD' ? $m['usd'] : $m['cobrado']));
                }
            }
            // Desglose por divisa para las tarjetas: solo las que tienen saldo o fondo,
            // USD y VES primero (base visible), luego EUR/COP cuando entraron al turno.
            $gavetaMonedas = $fondos = [];
            foreach ($r['monedas'] as $m) {
                $mon = trim($m['moneda']);
                if (abs((float) $m['esperado']) >= 0.005) {
                    $gavetaMonedas[] = ['moneda' => $mon, 'monto' => (float) $m['esperado']];
                }
                if ((float) $m['fondo'] >= 0.005) {
                    $fondos[] = ['moneda' => $mon, 'monto' => (float) $m['fondo']];
                }
                if ($mon === 'VES') {
                    $gavetaVes = (float) $m['esperado'];
                    continue;
                }
                $gavetaUsd += (float) $m['esperado'];
            }
            $porOrden = fn (array $x, array $y) => ($orden[$x['moneda']] ?? 9) <=> ($orden[$y['moneda']] ?? 9);
            usort($gavetaMonedas, $porOrden);
            usort($fondos, $porOrden);
            $bancoMonedas = [];
            foreach ($orden as $mon => $_) {
                if (($bancoPorMoneda[$mon] ?? 0) >= 0.005) {
                    $bancoMonedas[] = ['moneda' => $mon, 'monto' => round($bancoPorMoneda[$mon], 2)];
                }
            }
            $cajas[] = [
                'id' => (int) $a->id, 'codigo' => 'C' . ($i + 1), 'cajero' => $a->nombre_completo,
                'es_mia' => (int) $a->usuario_id === (int) auth()->id(), 'fecha_apertura' => $a->fecha_apertura,
                'ventas' => (int) $r['ventas'], 'total_usd' => (float) $r['total_usd'], 'total_ves' => (float) $r['total_ves'],
                'gaveta_usd' => round($gavetaUsd, 2), 'gaveta_ves' => round($gavetaVes, 2), 'gaveta_monedas' => $gavetaMonedas,
                'banco_usd' => round($bancoUsd, 2), 'banco_ves' => round($bancoVes, 2), 'banco_pagos' => $bancoPagos, 'banco_monedas' => $bancoMonedas,
                'fondos' => $fondos,
            ];
        }
        return Inertia::render('CajaEnVivo/Index', [...$this->contexto(), 'cajas' => $cajas,
            'tiene_caja_abierta' => (bool) DB::scalar('SELECT fn_apertura_activa(?)', [auth()->id()])]);
    }

    /** GET /caja/cerrar → pantalla dedicada para cerrar tu turno: arqueo + «El turno» + botón de corte Z. */
    public function paginaCerrar()
    {
        $ap = app(AperturaCajaController::class);
        $activa = $ap->activa();
        if (! $activa) {
            // Sin turno abierto no hay nada que cerrar: el POS muestra la apertura.
            return redirect()->to('/caja');
        }
        $caja = app(CashRegisterService::class);
        return Inertia::render('Caja/Cerrar', [...$this->contexto(), 'resumen' => $ap->resumen($activa['id']),
            'tasas' => $caja->tasasVigentes(), // se congelarán en el snapshot al firmar el cierre
            'decimales' => $caja->decimalesPorMoneda()]); // precisión por moneda (COP = 0)
    }

    /** GET /caja/abrir → pantalla de apertura del turno (el menú lateral «Abrir caja»). */
    public function paginaAbrir()
    {
        if (DB::scalar('SELECT fn_apertura_activa(?)', [auth()->id()])) {
            // Ya tienes caja abierta: lleva al monitor en vivo.
            return redirect()->to('/caja-en-vivo');
        }
        $neg = DB::selectOne('SELECT * FROM negocio WHERE id = 1');
        $tasas = [];
        foreach (DB::select("SELECT moneda_origen AS moneda, tasa, fecha_valor::text AS fecha, vigente, fuente FROM v_tasas_actuales WHERE moneda_destino = 'VES' AND moneda_origen IN ('USD','EUR','COP')") as $t) {
            $tasas[$t->moneda] = ['tasa' => (float) $t->tasa, 'fecha' => $t->fecha, 'vigente' => (bool) $t->vigente, 'fuente' => $t->fuente];
        }
        return Inertia::render('Caja/Abrir', [...$this->contexto(),
            'tasas' => $tasas, 'estado' => ! isset($tasas['USD']) || ! $tasas['USD']['vigente'] ? 'sin_tasa' : 'ok']);
    }

    /** Configuración que necesita la pantalla: régimen, tasas del día, métodos de pago y últimas ventas. */
    public function datosPagina(): array
    {
        $neg = DB::selectOne('SELECT * FROM negocio WHERE id = 1');
        $tasas = DB::select("SELECT moneda_origen AS moneda, tasa, fecha_valor::text AS fecha, vigente, fuente FROM v_tasas_actuales WHERE moneda_destino = 'VES' AND moneda_origen IN ('USD','EUR','COP')");
        $porMoneda = [];
        foreach ($tasas as $t) {
            $porMoneda[$t->moneda] = ['tasa' => (float) $t->tasa, 'fecha' => $t->fecha, 'vigente' => (bool) $t->vigente, 'fuente' => $t->fuente];
        }
        $metodos = DB::select("SELECT m.id, m.nombre, m.moneda, m.es_divisa, m.aplica_igtf, m.requiere_referencia FROM metodos_pago m JOIN monedas mo ON mo.codigo = m.moneda
                                WHERE m.activo AND mo.activa ORDER BY m.es_divisa, m.id");
        $estado = ! $neg || ! $neg->configurado_en ? 'sin_configurar' : (! isset($porMoneda['USD']) || ! $porMoneda['USD']['vigente'] ? 'sin_tasa' : 'ok');
        $fd = ['activa' => false, 'auto' => false];
        try {
            $fiscal = new EmisorFiscal();
            $fd = ['activa' => $fiscal->activo(), 'auto' => $fiscal->autoEmitir()];
        } catch (Throwable) {
            // sin tabla de facturación digital: el POS sigue con el número manual
        }

        return [
            'estado' => $estado,
            'apertura' => app(AperturaCajaController::class)->activa(),
            'emisor' => ['razon_social' => $neg->razon_social ?? '', 'nombre_comercial' => $neg->nombre_comercial ?? null, 'rif' => $neg->rif ?? '', 'direccion' => $neg->direccion_fiscal ?? '', 'telefono' => $neg->telefono ?? ''],
            'regimen' => [
                'especial' => (bool) ($neg->es_contribuyente_especial ?? false), 'cobra_igtf' => (bool) ($neg->cobra_igtf ?? false), 'igtf_base' => $neg->igtf_base ?? 'CON_IVA',
                'precios_incluyen_iva' => (bool) ($neg->precios_incluyen_iva ?? false), 'stock_negativo' => (bool) ($neg->permitir_stock_negativo ?? false),
                'tolerancia' => (float) ($neg->tolerancia_pago_usd ?? 0.01),
                'iva_pct' => (float) DB::scalar('SELECT fn_iva_pct()'),
                'igtf_pct' => ($neg->cobra_igtf ?? false) ? (float) DB::scalar('SELECT fn_igtf_pct()') : 0.0,
            ],
            'tasas' => $porMoneda,
            'factura_automatica' => $this->facturaAutomatica(),
            'facturacion_digital' => $fd,
            'metodos' => array_map(fn ($m) => ['id' => (int) $m->id, 'nombre' => $m->nombre, 'moneda' => trim($m->moneda), 'divisa' => (bool) $m->es_divisa, 'igtf' => (bool) $m->aplica_igtf, 'referencia' => (bool) $m->requiere_referencia], $metodos),
            'recientes' => $this->recientes(),
        ];
    }

    public function recientes(): array
    {
        $admin = (auth()->user()->rol ?? '') === 'ADMINISTRADOR';
        $filas = DB::select("SELECT v.id, v.numero, to_char(v.fecha_hora AT TIME ZONE 'America/Caracas', 'HH24:MI') AS hora, COALESCE(c.nombre, 'Consumidor final') AS cliente, v.total_usd, v.total_cobrar_ves,
                                    v.estado, v.numero_factura_fiscal AS factura
                               FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id
                              WHERE v.fecha_valor = fn_hoy() " . ($admin ? '' : 'AND v.usuario_id = ?') . ' ORDER BY v.id DESC LIMIT 8', $admin ? [] : [auth()->id()]);
        return array_map(fn ($v) => ['id' => (int) $v->id, 'numero' => (int) $v->numero, 'hora' => $v->hora, 'cliente' => $v->cliente, 'total_usd' => (float) $v->total_usd,
            'total_ves' => (float) $v->total_cobrar_ves, 'estado' => $v->estado, 'factura' => $v->factura], $filas);
    }

    /* ───────────── Búsqueda de productos (texto, SKU, código de barras, moto compatible) ───────────── */

    public function buscar(Request $r)
    {
        return response()->json($this->buscarDatos((string) $r->query('q', ''), $r->boolean('exacto')));
    }

    public function buscarDatos(string $q, bool $soloExacto = false): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $cols = 'v.id, v.sku, v.codigo_barras, v.nombre, v.marca, v.categoria, v.ubicacion_almacen, v.unidad, v.stock_actual, v.exento_iva, v.exonerado_iva, v.precio_venta_usd, v.precio_final_usd, v.precio_final_ves';
        if ($soloExacto) {
            $filas = DB::select("SELECT {$cols}, TRUE AS exacto FROM v_productos_precios v WHERE v.activo AND (v.codigo_barras = ? OR upper(v.sku) = upper(?)
                    OR EXISTS (SELECT 1 FROM producto_presentaciones pp WHERE pp.producto_id = v.id AND pp.activa AND pp.codigo_barras = ?)) LIMIT 2", [$q, $q, $q]);
        } else {
            $where = ['v.activo']; $b = [];
            foreach (preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $w) . '%';
                $where[] = "(unaccent(v.nombre) ILIKE unaccent(?) OR v.sku ILIKE ? OR v.codigo_barras ILIKE ? OR v.referencia_fabricante ILIKE ? OR unaccent(COALESCE(v.marca, '')) ILIKE unaccent(?)
                             OR unaccent(COALESCE(v.categoria, '')) ILIKE unaccent(?)
                             OR EXISTS (SELECT 1 FROM v_compatibilidad vc WHERE vc.producto_id = v.id AND unaccent(vc.marca || ' ' || vc.modelo) ILIKE unaccent(?)))";
                array_push($b, $like, $like, $like, $like, $like, $like, $like);
            }
            $filas = DB::select("SELECT {$cols}, (v.codigo_barras = ? OR upper(v.sku) = upper(?)) AS exacto FROM v_productos_precios v WHERE " . implode(' AND ', $where)
                . ' ORDER BY exacto DESC, (v.stock_actual > 0) DESC, v.nombre LIMIT 12', [$q, $q, ...$b]);  // los dos primeros son los del campo «exacto»
        }
        $pres = $this->presentacionesDe(array_map(fn ($p) => (int) $p->id, $filas));
        return array_map(fn ($p) => [
            'presentaciones' => $pres[(int) $p->id] ?? [],
            'id' => (int) $p->id, 'sku' => $p->sku, 'barras' => $p->codigo_barras, 'nombre' => $p->nombre, 'marca' => $p->marca, 'categoria' => $p->categoria, 'ubicacion' => $p->ubicacion_almacen,
            'unidad' => trim($p->unidad), 'stock' => (float) $p->stock_actual, 'exento' => (bool) ($p->exento_iva || $p->exonerado_iva), 'precio_usd' => (float) $p->precio_venta_usd,
            'final_usd' => (float) $p->precio_final_usd, 'final_ves' => (float) $p->precio_final_ves, 'exacto' => (bool) $p->exacto,
        ], $filas);
    }

    /* ───────────── Clientes ───────────── */

    public function clientes(Request $r)
    {
        return response()->json($this->clientesDatos((string) $r->query('q', '')));
    }

    public function clientesDatos(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $solo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $filas = DB::select("SELECT id, tipo_documento, documento, nombre, telefono, direccion FROM clientes
                              WHERE activo AND (unaccent(nombre) ILIKE unaccent(?) OR (? <> '' AND (tipo_documento || documento) ILIKE ?) OR (? <> '' AND documento ILIKE ?))
                              ORDER BY nombre LIMIT 8", [$like, $solo, "%{$solo}%", $solo, "%{$solo}%"]);
        return array_map(fn ($c) => ['id' => (int) $c->id, 'tipo' => trim($c->tipo_documento), 'documento' => $c->documento, 'nombre' => $c->nombre, 'telefono' => $c->telefono, 'direccion' => $c->direccion], $filas);
    }

    public function clienteStore(Request $r)
    {
        $d = $r->validate([
            'tipo' => 'required|in:V,E,J,G,P,C', 'documento' => 'required|string|max:20', 'nombre' => 'required|string|min:2|max:200',
            'telefono' => 'nullable|string|max:40', 'direccion' => 'nullable|string|max:300',
        ], ['nombre.required' => 'Escribe el nombre o la razón social.', 'documento.required' => 'Escribe la cédula o el RIF.']);
        $doc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $d['documento']));
        $tipo = $d['tipo'];
        if (in_array($tipo, ['V', 'E'], true) && ! preg_match('/^\d{5,9}$/', $doc)) {
            return response()->json(['message' => 'La cédula tiene entre 5 y 9 números.', 'errors' => ['documento' => ['La cédula tiene entre 5 y 9 números.']]], 422);
        }
        if (in_array($tipo, ['J', 'G', 'C'], true)) {
            if (! preg_match('/^\d{9}$/', $doc) || ! DB::scalar('SELECT fn_validar_rif(?)', [$tipo . $doc])) {
                return response()->json(['message' => 'El RIF no es válido. Ejemplo: J-12345678-4.', 'errors' => ['documento' => ['El RIF no es válido (revisa el dígito final). Ejemplo: J-12345678-4.']]], 422);
            }
        }
        if ($tipo === 'P' && ! preg_match('/^[A-Z0-9]{5,20}$/', $doc)) {
            return response()->json(['message' => 'El pasaporte tiene entre 5 y 20 letras o números.', 'errors' => ['documento' => ['El pasaporte tiene entre 5 y 20 letras o números.']]], 422);
        }
        $ya = DB::selectOne('SELECT id, tipo_documento, documento, nombre, telefono, direccion FROM clientes WHERE tipo_documento = ? AND documento = ?', [$tipo, $doc]);
        if ($ya) {
            return response()->json(['cliente' => ['id' => (int) $ya->id, 'tipo' => trim($ya->tipo_documento), 'documento' => $ya->documento, 'nombre' => $ya->nombre, 'telefono' => $ya->telefono, 'direccion' => $ya->direccion], 'existente' => true]);
        }
        $c = DB::selectOne('INSERT INTO clientes (tipo_documento, documento, nombre, telefono, direccion) VALUES (?, ?, ?, ?, ?) RETURNING id', [$tipo, $doc, trim($d['nombre']), $d['telefono'] ?? null, $d['direccion'] ?? null]);
        return response()->json(['cliente' => ['id' => (int) $c->id, 'tipo' => $tipo, 'documento' => $doc, 'nombre' => trim($d['nombre']), 'telefono' => $d['telefono'] ?? null, 'direccion' => $d['direccion'] ?? null], 'existente' => false]);
    }

    /* ───────────── Totales mientras se arma la venta ───────────── */

    public function cotizar(Request $r)
    {
        try {
            return response()->json($this->cotizarDatos($this->items($r->input('items', []))));
        } catch (QueryException $e) {
            return response()->json(['message' => $this->mensaje($e, 'No se pudo calcular la venta.')], 422);
        }
    }

    /**
     * Presentaciones activas de varios productos, con el precio final de UNA presentación (IVA y Bs incluidos, igual que la línea de venta).
     * Devuelve [producto_id => [{id, codigo, nombre, factor, barras, final_usd, final_ves}]].
     */
    private function presentacionesDe(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $filas = DB::select("SELECT pp.id, pp.producto_id, pp.codigo, pp.nombre, pp.factor, pp.codigo_barras, l.total_usd, l.total_ves
                               FROM producto_presentaciones pp JOIN productos p ON p.id = pp.producto_id CROSS JOIN negocio n
                               CROSS JOIN LATERAL fn_calcular_linea_venta(1, COALESCE(pp.precio_usd, ROUND(p.precio_venta_usd * pp.factor, 4)), 0, p.exento_iva OR p.exonerado_iva, fn_iva_pct(), n.precios_incluyen_iva, (fn_tasa_aplicable()).tasa) l
                              WHERE pp.activa AND pp.producto_id IN ({$in}) ORDER BY pp.factor, pp.id", $ids);
        $out = [];
        foreach ($filas as $f) {
            $out[(int) $f->producto_id][] = ['id' => (int) $f->id, 'codigo' => $f->codigo, 'nombre' => $f->nombre, 'factor' => (float) $f->factor,
                'barras' => $f->codigo_barras, 'final_usd' => (float) $f->total_usd, 'final_ves' => (float) $f->total_ves];
        }
        return $out;
    }

    public function cotizarDatos(array $items): array
    {
        if (! $items) {
            return ['vacio' => true];
        }
        $json = json_encode($items);
        $t = DB::selectOne('SELECT * FROM fn_cotizar_venta(?::jsonb)', [$json]);
        $lineas = DB::select('SELECT e.ord, x.producto_id, l.base_usd, l.iva_usd, l.total_usd, l.base_ves, l.iva_ves, l.total_ves
                                FROM jsonb_array_elements(?::jsonb) WITH ORDINALITY AS e(j, ord) CROSS JOIN LATERAL jsonb_to_record(e.j) AS x(producto_id bigint, cantidad numeric, descuento_pct numeric, presentacion_id bigint)
                                JOIN productos p ON p.id = x.producto_id CROSS JOIN negocio n
                                LEFT JOIN producto_presentaciones pp ON pp.id = x.presentacion_id AND pp.producto_id = x.producto_id
                                CROSS JOIN LATERAL fn_calcular_linea_venta(x.cantidad, COALESCE(pp.precio_usd, ROUND(p.precio_venta_usd * pp.factor, 4), p.precio_venta_usd), COALESCE(x.descuento_pct, 0), p.exento_iva OR p.exonerado_iva, fn_iva_pct(), n.precios_incluyen_iva, (fn_tasa_aplicable()).tasa) l
                               ORDER BY e.ord', [$json]);
        $f = fn ($v) => (float) $v;
        return [
            'tasa' => $f($t->tasa_cambio), 'tasa_fecha' => $t->tasa_fecha_valor, 'iva_pct' => $f($t->iva_pct),
            'base_usd' => $f($t->base_usd), 'exento_usd' => $f($t->exento_usd), 'iva_usd' => $f($t->iva_usd), 'total_usd' => $f($t->total_usd),
            'base_ves' => $f($t->base_ves), 'exento_ves' => $f($t->exento_ves), 'iva_ves' => $f($t->iva_ves), 'total_ves' => $f($t->total_ves),
            'lineas' => array_map(fn ($l) => ['producto_id' => (int) $l->producto_id, 'clave' => (int) $l->ord - 1, 'total_usd' => $f($l->total_usd), 'total_ves' => $f($l->total_ves)], $lineas),
        ];
    }

    /* ───────────── Registrar la venta ───────────── */

    public function registrar(Request $r)
    {
        $r->validate(['pagos' => 'nullable|array', 'items' => 'required|array|min:1', 'es_credito' => 'nullable|boolean', 'dias_credito' => 'nullable|integer|between:0,365', 'cliente_id' => 'nullable|integer',
            'tipo_emision' => 'nullable|in:PRESENCIAL,DIGITAL,MAQUINA_FISCAL', 'observaciones' => 'nullable|string|max:300'],
            ['items.required' => 'Agrega al menos un producto.']);
        try {
            return response()->json($this->registrarDatos($r->input('items'), (array) $r->input('pagos', []), $r->input('cliente_id'), $r->input('observaciones'),
                $r->input('tipo_emision', 'PRESENCIAL'), (bool) $r->input('es_credito', false), (int) $r->input('dias_credito', 30)));
        } catch (VentaInvalida $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            return response()->json(['message' => $this->mensaje($e, 'No se pudo registrar la venta.')], 422);
        }
    }

    /** ¿Este cliente ya debe algo? La caja lo avisa al elegirlo (no bloquea la venta). */
    public function creditoVerificar(Request $r)
    {
        $id = (int) $r->query('cliente_id', 0);
        $f = DB::selectOne("SELECT count(*) AS n, COALESCE(sum(saldo_usd), 0) AS saldo, count(*) FILTER (WHERE fecha_vencimiento < fn_hoy()) AS vencidas
                              FROM cuentas_por_cobrar WHERE cliente_id = ? AND estado = 'PENDIENTE'", [$id]);
        return response()->json(['tiene_deuda' => (int) $f->n > 0, 'cuentas' => (int) $f->n, 'saldo_usd' => round((float) $f->saldo, 2), 'vencidas' => (int) $f->vencidas]);
    }

    public function registrarDatos(array $items, array $pagos, $clienteId, ?string $obs, string $tipoEmision = 'PRESENCIAL', bool $esCredito = false, int $diasCredito = 30): array
    {
        // Sin turno abierto no se vende: el efectivo de la venta tiene que caer en un cierre de caja.
        if (! DB::scalar('SELECT fn_apertura_activa(?)', [auth()->id()])) {
            throw new VentaInvalida('Debe abrir la caja para poder vender.');
        }
        $its = $this->items($items);
        $pgs = [];
        foreach ($pagos as $p) {
            $monto = round((float) ($p['monto'] ?? 0), 2);
            if ($monto <= 0 || empty($p['metodo_pago_id'])) {
                continue;
            }
            $pgs[] = array_filter([
                'metodo_pago_id' => (int) $p['metodo_pago_id'], 'monto' => $monto, 'referencia' => isset($p['referencia']) ? trim((string) $p['referencia']) : null,
                'monto_entregado' => isset($p['monto_entregado']) ? round((float) $p['monto_entregado'], 2) : null, 'vuelto_monto' => isset($p['vuelto_monto']) ? round((float) $p['vuelto_monto'], 2) : null,
                'vuelto_moneda' => $p['vuelto_moneda'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
        }
        $this->validarAntes($its, $pgs, $esCredito, $clienteId ? (int) $clienteId : null);
        $id = (int) DB::scalar('SELECT fn_registrar_venta(?::bigint, ?::jsonb, ?::jsonb, ?::bigint, ?::text, ?::text, ?::boolean, ?::integer)', [auth()->id(), json_encode($its), json_encode($pgs), $clienteId ?: null, $obs ?: null, $tipoEmision, $esCredito ? 'true' : 'false', max(0, min(365, $diasCredito))]);
        $aviso = null;
        if ($this->facturaAutomatica()) {
            $aviso = $this->emitirAutomatica($id); // la venta ya quedó registrada; si la factura falla, se puede emitir después sin perder nada
        }
        $doc = $this->documentoDatos($id);
        $doc['aviso_emision'] = $aviso;
        return $doc;
    }

    /**
     * Revisa stock, referencias y cuadre de pagos ANTES de llamar a fn_registrar_venta. La secuencia del número de venta no se devuelve
     * cuando una transacción falla (ver v_ventas_huecos): validar aquí evita dejar huecos en la numeración por errores que se pueden prever.
     * Los errores imprevistos (dos cajas vendiendo el último repuesto a la vez) los sigue frenando la base de datos.
     */
    private function validarAntes(array $items, array $pagos, bool $esCredito = false, ?int $clienteId = null): void
    {
        if (! $items) {
            throw new VentaInvalida('Agrega al menos un producto.');
        }
        if ($esCredito) {
            if (! $clienteId) {
                throw new VentaInvalida('Una venta a crédito necesita un cliente: elige uno (no vale «Consumidor final»).');
            }
            if (! DB::selectOne('SELECT 1 FROM clientes WHERE id = ? AND activo', [$clienteId])) {
                throw new VentaInvalida('El cliente elegido no existe o está inactivo.');
            }
        } elseif (! $pagos) {
            throw new VentaInvalida('Indica al menos un pago.');
        }
        $neg = DB::selectOne('SELECT permitir_stock_negativo, tolerancia_pago_usd, configurado_en FROM negocio WHERE id = 1');
        if (! $neg->configurado_en) {
            throw new VentaInvalida('El negocio aún no está configurado: termina el asistente de primer inicio.');
        }
        if (! $neg->permitir_stock_negativo) {
            $pedido = [];
            foreach ($items as $i) {
                $pedido[$i['producto_id']] = ($pedido[$i['producto_id']] ?? 0) + $i['cantidad'];
            }
            foreach ($pedido as $pid => $cant) {
                $p = DB::selectOne('SELECT nombre, sku, stock_actual, activo FROM productos WHERE id = ?', [$pid]);
                if (! $p || ! $p->activo) {
                    throw new VentaInvalida('Uno de los productos ya no está disponible. Quítalo de la venta.');
                }
                if ((float) $p->stock_actual < $cant) {
                    throw new VentaInvalida(sprintf('No hay suficiente "%s" (%s): quedan %s y pides %s.', $p->nombre, $p->sku, rtrim(rtrim(number_format((float) $p->stock_actual, 3, ',', '.'), '0'), ','), rtrim(rtrim(number_format($cant, 3, ',', '.'), '0'), ',')));
                }
            }
        }
        $cot = $this->cotizarDatos($items);
        $tasa = $cot['tasa'];
        $pagadoUsd = 0.0;
        foreach ($pagos as $p) {
            $m = DB::selectOne('SELECT nombre, moneda, requiere_referencia, activo FROM metodos_pago WHERE id = ?', [$p['metodo_pago_id']]);
            if (! $m || ! $m->activo) {
                throw new VentaInvalida('Uno de los métodos de pago ya no está activo.');
            }
            if ($m->requiere_referencia && trim((string) ($p['referencia'] ?? '')) === '') {
                throw new VentaInvalida("«{$m->nombre}» necesita el número de referencia.");
            }
            $mon = trim($m->moneda);
            if ($mon === 'USD') {
                $pagadoUsd += $p['monto'];
            } elseif ($mon === 'VES') {
                $pagadoUsd += round($p['monto'] / $tasa, 2);
            } else {
                $directa = (float) DB::scalar("SELECT (fn_tasa_aplicable(NULL, ?, 'VES')).tasa", [$mon]);
                $pagadoUsd += round(round($p['monto'] * $directa, 2) / $tasa, 2);
            }
        }
        // A crédito solo se exige que no se pague de más; al contado, que los pagos cuadren con el total.
        if ($esCredito && $pagadoUsd - $cot['total_usd'] > (float) $neg->tolerancia_pago_usd + 1e-9) {
            throw new VentaInvalida(sprintf('El abono supera el total en USD %s.', number_format($pagadoUsd - $cot['total_usd'], 2, ',', '.')));
        }
        if (! $esCredito && abs($pagadoUsd - $cot['total_usd']) > (float) $neg->tolerancia_pago_usd + 1e-9) {
            $dif = $cot['total_usd'] - $pagadoUsd;
            throw new VentaInvalida(sprintf($dif > 0 ? 'Faltan USD %s para completar el pago.' : 'Los pagos superan el total en USD %s.', number_format(abs($dif), 2, ',', '.')));
        }
    }

    /** Asigna el número de factura fiscal (y de control) emitido por el medio de facturación. Una sola vez por venta. */
    public function factura(Request $r, int $id)
    {
        // «Emitir factura ahora»: sin número manual y con emisor disponible, la emisión la hace TFHKA o el correlativo local.
        if (! $r->filled('factura') && $this->emisionDisponible()) {
            $aviso = $this->emitirAutomatica($id);
            if ($aviso) {
                return response()->json(['message' => $aviso], 422);
            }
            return response()->json($this->documentoDatos($id));
        }
        $d = $r->validate(['factura' => 'required|string|max:40', 'control' => 'nullable|string|max:40'], ['factura.required' => 'Escribe el número de la factura.']);
        try {
            DB::statement('CALL sp_asignar_factura_fiscal(?, ?, ?)', [$id, $d['factura'], $d['control'] ?? null]);
        } catch (QueryException $e) {
            return response()->json(['message' => $this->mensaje($e, 'Ese número de factura ya está en uso.'), 'errors' => ['factura' => [$this->mensaje($e, 'Ese número de factura ya está en uso.')]]], 422);
        }
        return response()->json($this->documentoDatos($id));
    }

    /* ───────── Factura automática (09_numeracion_fiscal.sql) ───────── */

    /** ¿La factura se emite sola al cobrar (TFHKA auto o numeración local)? */
    public function facturaAutomatica(): bool
    {
        try {
            $fiscal = new EmisorFiscal();
            if ($fiscal->activo()) {
                return $fiscal->autoEmitir();
            }
        } catch (Throwable) {
            // sigue con numeración local
        }

        return $this->numeracionLocalActiva();
    }

    /** ¿Hay un emisor disponible (TFHKA activo o numeración local)? */
    private function emisionDisponible(): bool
    {
        try {
            if ((new EmisorFiscal())->activo()) {
                return true;
            }
        } catch (Throwable) {
            // sigue con numeración local
        }

        return $this->numeracionLocalActiva();
    }

    private function numeracionLocalActiva(): bool
    {
        try {
            return (bool) DB::scalar("SELECT CASE WHEN to_regclass('numeracion_fiscal') IS NULL THEN false ELSE COALESCE((SELECT automatica FROM numeracion_fiscal WHERE id = 1), false) END");
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Emite la factura fiscal de una venta:
     *   · Si la tienda tiene TFHKA activa, llama a la imprenta digital y guarda
     *     el número de documento, el número de control, la URL del QR y el PDF.
     *   · Si no, usa el correlativo local (09_numeracion_fiscal.sql).
     */
    private function emitirAutomatica(int $id): ?string
    {
        try {
            $fiscal = new EmisorFiscal();
            if ($fiscal->activo()) {
                // Máquina fiscal: impresora local, el papel sale en el momento y
                // es parte del cobro → síncrona como siempre.
                if ($fiscal->maquina()->modo() === 'MAQUINA_FISCAL') {
                    try {
                        $fiscal->emitirVenta($id, auth()->id());

                        return null;
                    } catch (Throwable $e) {
                        return $e->getMessage() ?: 'La máquina fiscal no pudo imprimir la factura; quedó pendiente para reintentar.';
                    }
                }
                // Imprenta digital TFHKA (API por red) → COLA
                // (App\Jobs\EmitirDocumentoFiscal): la caja nunca espera a TFHKA
                // ni pierde la venta por un corte de red. Con
                // QUEUE_CONNECTION=sync corre inline (dev/pruebas) y con
                // «database» en producción el worker reintenta con backoff.
                EmitirDocumentoFiscal::dispatch($id, auth()->id());

                return null;
            }
        } catch (Throwable $e) {
            return $e->getMessage() ?: 'TFHKA no pudo emitir la factura; quedó pendiente para reintentar.';
        }

        try {
            DB::selectOne('SELECT * FROM fn_emitir_factura_fiscal(?)', [$id]);

            return null;
        } catch (QueryException $e) {
            return $this->mensaje($e, 'La venta se registró, pero no se pudo emitir la factura. Inténtalo de nuevo.');
        }
    }

    public function emitir(int $id)
    {
        abort_if(! $this->emisionDisponible(), 422, 'La emisión automática está desactivada.');
        $aviso = $this->emitirAutomatica($id);
        if ($aviso) {
            return response()->json(['message' => $aviso], 422);
        }
        return response()->json($this->documentoDatos($id));
    }

    public function documento(int $id)
    {
        $d = $this->documentoDatos($id);
        abort_if(! $d, 404);
        return response()->json($d);
    }

    /** Todo lo necesario para imprimir la factura, la nota de venta o el ticket: lo arma el servicio común (PDF, POS y TFHKA usan la misma fuente). */
    public function documentoDatos(int $id): ?array
    {
        return (new DatosDocumento())->venta($id);
    }

    /** Solo producto, cantidad y descuento: lo demás lo decide la base de datos. Une líneas repetidas del mismo producto y descuento. */
    private function items(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $pid = (int) ($i['producto_id'] ?? 0);
            $cant = round((float) ($i['cantidad'] ?? 0), 3);
            $desc = max(0, min(100, round((float) ($i['descuento_pct'] ?? 0), 2)));
            $pres = (int) ($i['presentacion_id'] ?? 0);
            if ($pid > 0 && $cant > 0) {
                $out[] = ['producto_id' => $pid, 'cantidad' => $cant, 'descuento_pct' => $desc] + ($pres > 0 ? ['presentacion_id' => $pres] : []);
            }
        }
        return $out;
    }

    private function mensaje(QueryException $e, string $porDefecto): string
    {
        if ($e->getCode() === '23505') {
            return $porDefecto;
        }
        return preg_match('/ERROR:\s*(.+?)(?:\s*CONTEXT:|\n|\(Connection|$)/su', $e->getMessage(), $m) ? trim($m[1]) : $porDefecto;
    }
}

/** Venta rechazada antes de tocar la base de datos (no consume número de venta). */
class VentaInvalida extends \RuntimeException
{
}
