<?php

namespace App\Services\Tfhka;

use Illuminate\Support\Facades\DB;

/**
 * Datos de una venta o nota en el formato que consumen el PDF, el POS y la
 * facturación digital. Es la única fuente: CajaController::documentoDatos()
 * delega aquí para que la factura legal y el ticket no se desincronicen.
 */
class DatosDocumento
{
    public function venta(int $id): ?array
    {
        $v = DB::selectOne("SELECT v.id, v.numero, v.fecha_hora, v.fecha_valor, v.tasa_cambio, v.tasa_fecha_valor,
                                   v.iva_pct, v.precios_incluyen_iva, v.cobra_igtf, v.igtf_pct, v.igtf_base,
                                   v.base_usd, v.exento_usd, v.iva_usd, v.total_usd, v.igtf_usd, v.total_cobrar_usd,
                                   v.base_ves, v.exento_ves, v.iva_ves, v.total_ves, v.igtf_ves, v.total_cobrar_ves,
                                   v.estado, v.tipo_emision, v.observaciones, v.es_credito, v.saldo_pendiente_usd,
                                   v.numero_factura_fiscal, v.numero_control, v.url_consulta, v.tfhka_estado,
                                   (v.pdf_fiscal IS NOT NULL) AS tiene_pdf_fiscal,
                                   to_char(v.fecha_hora AT TIME ZONE 'America/Caracas', 'DD/MM/YYYY') AS f_txt,
                                   to_char(v.fecha_hora AT TIME ZONE 'America/Caracas', 'HH24:MI') AS h_txt,
                                   lower(to_char(v.fecha_hora AT TIME ZONE 'America/Caracas', 'HH12:MI:SS AM')) AS h_fiscal,
                                   c.tipo_documento AS c_tipo, c.documento AS c_doc, c.nombre AS c_nombre,
                                   c.telefono AS c_tel, c.direccion AS c_dir, c.correo AS c_correo,
                                   u.nombre_completo AS cajero
                              FROM ventas v
                              LEFT JOIN clientes c ON c.id = v.cliente_id
                              LEFT JOIN usuarios u ON u.id = v.usuario_id
                             WHERE v.id = ?", [$id]);
        if (! $v) {
            return null;
        }

        $neg = $this->negocio();
        $f = fn ($x) => $x === null ? null : (float) $x;
        $lineas = DB::select("SELECT p.sku, COALESCE(d.descripcion, p.nombre) AS descripcion,
                                     CASE WHEN d.presentacion_id IS NULL THEN trim(p.unidad) ELSE d.presentacion_codigo END AS unidad,
                                     COALESCE(d.cantidad_presentacion, d.cantidad) AS cantidad,
                                     COALESCE(d.precio_presentacion_usd, d.precio_unitario_usd) AS precio_unitario_usd,
                                     d.descuento_pct, d.alicuota_codigo, d.iva_pct, d.base_usd, d.iva_usd, d.total_usd,
                                     d.base_ves, d.iva_ves, d.total_ves
                                FROM detalle_venta d
                                JOIN productos p ON p.id = d.producto_id
                               WHERE d.venta_id = ?
                               ORDER BY d.id", [$id]);
        $pagos = DB::select('SELECT m.nombre, m.tfhka_forma, p.moneda, p.monto, p.referencia, p.igtf_monto, p.igtf_ves,
                                    p.monto_cobrado, p.monto_usd, p.monto_ves, p.monto_entregado,
                                    p.vuelto_monto, p.vuelto_moneda, p.tasa_cambio
                               FROM pagos_venta p
                               JOIN metodos_pago m ON m.id = p.metodo_pago_id
                              WHERE p.venta_id = ?
                              ORDER BY p.id', [$id]);
        $otras = DB::select('SELECT moneda, tasa FROM ventas_tasas WHERE venta_id = ?', [$id]);
        $tasas = ['USD' => $f($v->tasa_cambio)];
        foreach ($otras as $o) {
            $tasas[trim($o->moneda)] = $f($o->tasa);
        }

        return [
            'id' => (int) $v->id, 'numero' => (int) $v->numero, 'fecha' => $v->f_txt, 'hora' => $v->h_txt,
            'hora_fiscal' => $v->h_fiscal,
            'estado' => $v->estado, 'tipo_emision' => $v->tipo_emision, 'cajero' => $v->cajero,
            'factura' => $v->numero_factura_fiscal, 'control' => $v->numero_control,
            'url_consulta' => $v->url_consulta ?? null, 'tfhka_estado' => $v->tfhka_estado ?? null,
            'tiene_pdf_fiscal' => (bool) $v->tiene_pdf_fiscal,
            'observaciones' => $v->observaciones,
            'credito' => [
                'es' => (bool) $v->es_credito, 'saldo_usd' => $f($v->saldo_pendiente_usd),
                'vence' => $v->es_credito
                    ? DB::scalar("SELECT to_char(min(fecha_vencimiento), 'DD/MM/YYYY') FROM cuentas_por_cobrar WHERE venta_id = ?", [$id])
                    : null,
            ],
            'cliente' => $v->c_nombre ? [
                'tipo' => trim($v->c_tipo), 'documento' => $v->c_doc, 'nombre' => $v->c_nombre,
                'telefono' => $v->c_tel, 'direccion' => $v->c_dir, 'correo' => $v->c_correo,
            ] : null,
            'emisor' => [
                'razon_social' => $neg->razon_social, 'nombre_comercial' => $neg->nombre_comercial,
                'rif' => $neg->rif, 'direccion' => $neg->direccion_fiscal, 'telefono' => $neg->telefono,
                'correo' => $neg->correo,
            ],
            'regimen' => [
                'especial' => (bool) $neg->es_contribuyente_especial, 'cobra_igtf' => (bool) $v->cobra_igtf,
                'igtf_pct' => $f($v->igtf_pct), 'igtf_base' => $v->igtf_base, 'iva_pct' => $f($v->iva_pct),
                'precios_incluyen_iva' => (bool) $v->precios_incluyen_iva,
            ],
            'tasas' => $tasas, 'tasa_fecha' => $v->tasa_fecha_valor,
            'totales' => array_map($f, [
                'base_usd' => $v->base_usd, 'exento_usd' => $v->exento_usd, 'iva_usd' => $v->iva_usd,
                'total_usd' => $v->total_usd, 'igtf_usd' => $v->igtf_usd, 'total_cobrar_usd' => $v->total_cobrar_usd,
                'base_ves' => $v->base_ves, 'exento_ves' => $v->exento_ves, 'iva_ves' => $v->iva_ves,
                'total_ves' => $v->total_ves, 'igtf_ves' => $v->igtf_ves, 'total_cobrar_ves' => $v->total_cobrar_ves,
            ]),
            'lineas' => array_map(fn ($l) => [
                'sku' => $l->sku, 'descripcion' => $l->descripcion, 'unidad' => $l->unidad,
                'cantidad' => $f($l->cantidad), 'precio_usd' => $f($l->precio_unitario_usd),
                'descuento_pct' => $f($l->descuento_pct),
                'alicuota' => $l->alicuota_codigo === 'IVA_GENERAL' ? 'G' : ($l->alicuota_codigo === 'EXONERADO' ? 'F' : 'E'),
                'iva_pct' => $f($l->iva_pct),
                'base_usd' => $f($l->base_usd), 'iva_usd' => $f($l->iva_usd), 'total_usd' => $f($l->total_usd),
                'base_ves' => $f($l->base_ves), 'iva_ves' => $f($l->iva_ves), 'total_ves' => $f($l->total_ves),
            ], $lineas),
            'pagos' => array_map(fn ($p) => [
                'metodo' => $p->nombre, 'tfhka_forma' => $p->tfhka_forma,
                'moneda' => trim($p->moneda), 'monto' => $f($p->monto), 'referencia' => $p->referencia,
                'igtf' => $f($p->igtf_monto), 'igtf_ves' => $f($p->igtf_ves), 'cobrado' => $f($p->monto_cobrado),
                'usd' => $f($p->monto_usd), 'ves' => $f($p->monto_ves),
                'entregado' => $f($p->monto_entregado), 'vuelto' => $f($p->vuelto_monto),
                'vuelto_moneda' => $p->vuelto_moneda ? trim($p->vuelto_moneda) : null,
                'tasa' => $f($p->tasa_cambio),
            ], $pagos),
        ];
    }

    /** Nota de crédito/débito con su venta completa, para poder emitirla como documento fiscal. */
    public function nota(int $id): ?array
    {
        $n = DB::selectOne("SELECT n.*, to_char(n.fecha, 'DD/MM/YYYY') AS fecha_txt,
                                   lower(to_char(n.created_at AT TIME ZONE 'America/Caracas', 'HH12:MI:SS AM')) AS hora_fiscal,
                                   v.numero AS venta_numero, v.numero_factura_fiscal, v.numero_control AS venta_control,
                                   v.total_ves AS venta_total_ves, v.fecha_hora AS venta_fecha_hora,
                                   v.estado AS venta_estado, v.tasa_cambio,
                                   c.tipo_documento AS c_tipo, c.documento AS c_doc, c.nombre AS c_nombre,
                                   c.telefono AS c_tel, c.direccion AS c_dir, c.correo AS c_correo,
                                   u.nombre_completo AS usuario
                              FROM notas_ajuste n
                              JOIN ventas v ON v.id = n.venta_id
                              LEFT JOIN clientes c ON c.id = v.cliente_id
                              LEFT JOIN usuarios u ON u.id = n.usuario_id
                             WHERE n.id = ?", [$id]);
        if (! $n) {
            return null;
        }

        $venta = $this->venta((int) $n->venta_id);
        $f = fn ($x) => $x === null ? null : (float) $x;

        return [
            'id' => (int) $n->id, 'tipo' => $n->tipo, 'numero' => $n->numero,
            'numero_fiscal' => $n->numero_fiscal, 'numero_control' => $n->numero_control,
            'fecha' => $n->fecha_txt, 'base_ves' => $f($n->base_ves), 'iva_ves' => $f($n->iva_ves),
            'total_ves' => $f($n->total_ves), 'iva_pct' => $f($n->iva_pct), 'motivo' => $n->motivo,
            'hora_fiscal' => $n->hora_fiscal,
            'url_consulta' => $n->url_consulta, 'tfhka_estado' => $n->tfhka_estado,
            'tiene_pdf_fiscal' => ! empty($n->pdf_fiscal),
            'venta' => $venta,
            'venta_factura' => $n->numero_factura_fiscal,
            'venta_control' => $n->venta_control,
            'venta_total_ves' => $f($n->venta_total_ves),
            'cliente' => $n->c_nombre ? [
                'tipo' => trim($n->c_tipo), 'documento' => $n->c_doc, 'nombre' => $n->c_nombre,
                'telefono' => $n->c_tel, 'direccion' => $n->c_dir, 'correo' => $n->c_correo,
            ] : null,
            'usuario' => $n->usuario,
        ];
    }

    public function negocio(): object
    {
        return DB::selectOne('SELECT * FROM negocio WHERE id = fn_negocio_actual()');
    }
}