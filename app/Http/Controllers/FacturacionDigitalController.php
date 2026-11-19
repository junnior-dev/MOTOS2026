<?php

namespace App\Http\Controllers;

use App\Services\Tfhka\EmisorFiscal;
use App\Support\Permisos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;

/**
 * Configuración y operación de la facturación digital (The Factory HKA).
 * Solo el administrador entra. Toda la lógica fiscal vive en App\Services\Tfhka:
 * aquí solo se reciben datos, se validan y se devuelve el estado de la pantalla.
 */
class FacturacionDigitalController extends Controller
{
    use ContextoPagina;

    private function soloAdmin(): void
    {
        abort_unless(Permisos::esAdmin(auth()->user()->rol ?? ''), 403, 'Solo un administrador puede configurar la facturación digital.');
    }

    public function index()
    {
        $this->soloAdmin();

        return Inertia::render('FacturacionDigital/Index', $this->contexto() + $this->datos());
    }

    /** Estado completo de la pantalla; también lo devuelven las acciones para refrescar sin recargar. */
    public function datos(): array
    {
        $fiscal = new EmisorFiscal();
        $cfg = $fiscal->configPublica();
        $cfg['demo_url'] = (string) config('tfhka.demo_url');
        $cfg['produccion_url'] = (string) config('tfhka.produccion_url');
        // Modo de emisión fiscal (SQL 43): IMPRENTA_DIGITAL, MAQUINA_FISCAL o MANUAL.
        $cfg['modo'] = $fiscal->maquina()->modo();
        $cfg['maquina'] = $fiscal->maquina()->config();

        return [
            'config' => $cfg,
            'resumen' => $this->resumen(),
            'pendientes' => $this->pendientes(),
            'notas_pendientes' => $this->notasPendientes(),
            'eventos' => $this->eventos(),
            'metodos' => $this->metodos(),
        ];
    }

    /** POST /facturacion-digital: guarda modo, credenciales y datos de emisión (la clave se cifra). */
    public function guardar(Request $r)
    {
        $this->soloAdmin();
        $d = $r->validate([
            'activo' => 'nullable|boolean',
            'ambiente' => 'required|in:DEMO,PRODUCCION',
            'base_url' => 'nullable|url|max:200',
            'usuario' => 'nullable|string|max:150',
            'clave' => 'nullable|string|max:200',
            'serie' => 'nullable|string|max:20',
            'sucursal' => 'nullable|string|max:20',
            'codigo_ciiu' => 'nullable|string|max:10',
            'codigo_plu_default' => 'nullable|string|max:20',
            'usar_datos_negocio' => 'nullable|boolean',
            'auto_emitir' => 'nullable|boolean',
            'timeout_segundos' => 'nullable|integer|between:5,300',
            'moneda' => 'required|in:BSD,USD,EUR',
            'regimen_esp_tributacion' => 'nullable|string|max:55',
            // Modo de emisión fiscal e impresora autorizada (SQL 43)
            'modo' => 'required|in:IMPRENTA_DIGITAL,MAQUINA_FISCAL,MANUAL',
            'maquina_registro' => 'nullable|string|max:30',
            'maquina_ip' => 'nullable|string|max:120',
            'maquina_puerto' => 'nullable|integer|between:1,65535',
            'maquina_protocolo' => 'nullable|in:TCP,HTTP,SIMULADOR',
            'punto_emision' => 'nullable|string|max:4',
            'oficina' => 'nullable|string|max:4',
        ], [
            'ambiente.required' => 'Elige el ambiente de TFHKA.',
            'ambiente.in' => 'El ambiente debe ser DEMO o PRODUCCION.',
            'base_url.url' => 'La dirección de TFHKA no parece una URL válida.',
            'moneda.required' => 'Elige la moneda del documento fiscal.',
            'timeout_segundos.between' => 'El tiempo de espera debe estar entre 5 y 300 segundos.',
            'modo.required' => 'Elige el modo de emisión fiscal.',
            'modo.in' => 'El modo debe ser IMPRENTA_DIGITAL, MAQUINA_FISCAL o MANUAL.',
        ]);

        try {
            $fiscal = new EmisorFiscal();
            $fiscal->guardarConfig($d, auth()->id());
            $fiscal->maquina()->guardar($d);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'No se pudo guardar la configuración.'], 422);
        }

        return response()->json(['message' => 'Configuración guardada.'] + $this->datos());
    }

    /** POST /facturacion-digital/probar: autentica contra TFHKA sin emitir nada. */
    public function probar()
    {
        $this->soloAdmin();
        try {
            $r = (new EmisorFiscal())->probar(auth()->id());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'TFHKA rechazó las credenciales.'] + $this->datos(), 422);
        }

        return response()->json(['message' => $r['mensaje'] ?? 'Credenciales aceptadas por TFHKA.'] + $this->datos());
    }

    /** POST /facturacion-digital/ventas/{id}/emitir: emite o reintenta la factura de una venta. */
    public function emitirVenta(int $id)
    {
        $this->soloAdmin();
        try {
            $res = (new EmisorFiscal())->emitirVenta($id, auth()->id(), true);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'TFHKA no confirmó la factura.'] + $this->datos(), 422);
        }

        return response()->json([
            'message' => 'Factura emitida. Control '.($res['numero_control'] ?? '').'.',
            'resultado' => $res,
        ] + $this->datos());
    }

    /** POST /facturacion-digital/notas/{id}/emitir: emite o reintenta una nota de crédito/débito. */
    public function emitirNota(int $id)
    {
        $this->soloAdmin();
        try {
            $res = (new EmisorFiscal())->emitirNota($id, auth()->id(), true);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'TFHKA no confirmó la nota.'] + $this->datos(), 422);
        }

        return response()->json([
            'message' => 'Nota emitida. Control '.($res['numero_control'] ?? '').'.',
            'resultado' => $res,
        ] + $this->datos());
    }

    /** POST /facturacion-digital/notas/{id}/anular: anula una nota ya emitida (las facturas no se anulan). */
    public function anularNota(Request $r, int $id)
    {
        $this->soloAdmin();
        $d = $r->validate(['motivo' => 'required|string|min:3|max:300'], ['motivo.required' => 'Escribe el motivo de la anulación.']);
        try {
            (new EmisorFiscal())->anularNota($id, $d['motivo'], auth()->id());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'TFHKA no anuló la nota.'] + $this->datos(), 422);
        }

        return response()->json(['message' => 'Nota anulada en TFHKA.'] + $this->datos());
    }

    /** GET /facturacion-digital/ventas/{id}/pdf: PDF oficial de la factura (inline). */
    public function pdfVenta(int $id)
    {
        $this->soloAdmin();

        return $this->pdf('ventas', $id, 'Factura');
    }

    /** GET /facturacion-digital/notas/{id}/pdf: PDF oficial de la nota (inline). */
    public function pdfNota(int $id)
    {
        $this->soloAdmin();

        return $this->pdf('notas_ajuste', $id, 'Nota');
    }

    /** PUT /facturacion-digital/metodos/{id}: código de forma de pago que exige TFHKA. */
    public function metodo(Request $r, int $id)
    {
        $this->soloAdmin();
        $d = $r->validate(['tfhka_forma' => 'nullable|string|max:2']);
        $codigo = trim((string) ($d['tfhka_forma'] ?? ''));
        if ($codigo !== '' && ! preg_match('/^(0[1-9]|1[0-8]|99)$/', $codigo)) {
            return response()->json(['message' => 'El código de forma de pago debe ir del 01 al 18 (o 99).'], 422);
        }

        DB::update('UPDATE metodos_pago SET tfhka_forma = ? WHERE id = ?', [$codigo !== '' ? $codigo : null, $id]);

        return response()->json(['message' => 'Forma de pago actualizada.'] + $this->datos());
    }

    /** GET /facturacion-digital/numeraciones: rangos que TFHKA tiene asignados a la empresa. */
    public function numeraciones()
    {
        $this->soloAdmin();
        try {
            $r = (new EmisorFiscal())->cliente()->numeraciones();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'No se pudo consultar la numeración.'], 422);
        }

        return response()->json(['numeraciones' => $r]);
    }

    /* ───────────── Consultas de la pantalla ───────────── */

    private function resumen(): array
    {
        $r = ['emitidas' => 0, 'pendientes' => 0, 'errores' => 0, 'notas_emitidas' => 0, 'notas_pendientes' => 0, 'sin_factura' => 0, 'tabla' => false];
        try {
            $v = DB::selectOne("SELECT count(*) FILTER (WHERE tfhka_estado = 'EMITIDA') AS emitidas,
                                       count(*) FILTER (WHERE tfhka_estado = 'ERROR') AS errores,
                                       count(*) FILTER (WHERE estado = 'COMPLETADA' AND COALESCE(tfhka_estado, '') NOT IN ('EMITIDA')) AS pendientes,
                                       count(*) FILTER (WHERE estado = 'COMPLETADA' AND numero_factura_fiscal IS NULL) AS sin_factura
                                  FROM ventas");
            $n = DB::selectOne("SELECT count(*) FILTER (WHERE tfhka_estado = 'EMITIDA') AS emitidas,
                                       count(*) FILTER (WHERE tfhka_estado = 'ERROR') AS errores,
                                       count(*) FILTER (WHERE COALESCE(tfhka_estado, '') NOT IN ('EMITIDA')) AS pendientes
                                  FROM notas_ajuste");
            $r = [
                'emitidas' => (int) $v->emitidas, 'pendientes' => (int) $v->pendientes, 'errores' => (int) $v->errores,
                'sin_factura' => (int) $v->sin_factura,
                'notas_emitidas' => (int) $n->emitidas, 'notas_pendientes' => (int) $n->pendientes,
                'tabla' => true,
            ];
        } catch (Throwable) {
            // sin SQL 40 ejecutado: la pantalla muestra el aviso de instalación
        }

        return $r;
    }

    /** Ventas completadas sin factura fiscal confirmada (últimas 25). */
    private function pendientes(): array
    {
        try {
            return array_map(fn ($v) => [
                'id' => (int) $v->id, 'numero' => (int) $v->numero, 'fecha' => $v->fecha,
                'cliente' => $v->cliente, 'total_ves' => (float) $v->total_ves,
                'estado' => $v->tfhka_estado, 'factura' => $v->numero_factura_fiscal,
            ], DB::select("SELECT v.id, v.numero, to_char(v.fecha_hora AT TIME ZONE 'America/Caracas', 'DD/MM/YYYY HH24:MI') AS fecha,
                                   COALESCE(c.nombre, 'Consumidor final') AS cliente, v.total_ves, v.tfhka_estado AS estado, v.numero_factura_fiscal AS factura
                              FROM ventas v LEFT JOIN clientes c ON c.id = v.cliente_id
                             WHERE v.estado = 'COMPLETADA' AND COALESCE(v.tfhka_estado, '') <> 'EMITIDA'
                             ORDER BY v.id DESC LIMIT 25"));
        } catch (Throwable) {
            return [];
        }
    }

    /** Notas registradas sin confirmación fiscal (últimas 25). */
    private function notasPendientes(): array
    {
        try {
            return array_map(fn ($n) => [
                'id' => (int) $n->id, 'tipo' => $n->tipo, 'numero' => $n->numero,
                'venta_numero' => (int) $n->venta_numero, 'fecha' => $n->fecha, 'total_ves' => (float) $n->total_ves,
                'estado' => $n->tfhka_estado, 'numero_fiscal' => $n->numero_fiscal,
            ], DB::select("SELECT n.id, n.tipo, n.numero, n.numero_fiscal, n.tfhka_estado AS estado, v.numero AS venta_numero,
                                   to_char(n.fecha, 'DD/MM/YYYY') AS fecha, n.total_ves
                              FROM notas_ajuste n JOIN ventas v ON v.id = n.venta_id
                             WHERE COALESCE(n.tfhka_estado, '') <> 'EMITIDA'
                             ORDER BY n.id DESC LIMIT 25"));
        } catch (Throwable) {
            return [];
        }
    }

    private function eventos(): array
    {
        try {
            return array_map(fn ($e) => [
                'id' => (int) $e->id, 'fecha' => $e->fecha, 'operacion' => $e->operacion,
                'tipo_documento' => $e->tipo_documento, 'numero_documento' => $e->numero_documento,
                'estado' => $e->estado, 'mensaje' => $e->mensaje,
            ], DB::select("SELECT id, to_char(creado_en AT TIME ZONE 'America/Caracas', 'DD/MM/YYYY HH24:MI') AS fecha,
                                   operacion, tipo_documento, numero_documento, estado, mensaje
                              FROM tfhka_eventos ORDER BY id DESC LIMIT 30"));
        } catch (Throwable) {
            return [];
        }
    }

    private function metodos(): array
    {
        return array_map(fn ($m) => [
            'id' => (int) $m->id, 'nombre' => $m->nombre, 'moneda' => trim($m->moneda),
            'tfhka_forma' => $m->tfhka_forma, 'activo' => (bool) $m->activo,
        ], DB::select('SELECT id, nombre, moneda, tfhka_forma, activo FROM metodos_pago ORDER BY activo DESC, id'));
    }

    private function pdf(string $tabla, int $id, string $nombre)
    {
        $esNota = $tabla === 'notas_ajuste';
        $tabla = $esNota ? 'notas_ajuste' : 'ventas';
        $colNum = $esNota ? 'numero_fiscal' : 'numero_factura_fiscal';
        $colTipo = $esNota ? "CASE WHEN tipo = 'D' THEN '03' ELSE '02' END" : "'01'";
        $row = DB::selectOne("SELECT pdf_fiscal, {$colNum} AS numero_doc, numero_control, {$colTipo} AS tipo_doc FROM {$tabla} WHERE id = ?", [$id]);
        if (! $row) {
            abort(404);
        }

        $bytes = is_resource($row->pdf_fiscal) ? stream_get_contents($row->pdf_fiscal) : ($row->pdf_fiscal ?? null);
        if (($bytes === null || $bytes === '') && ! empty($row->numero_doc)) {
            try {
                $fiscal = new EmisorFiscal();
                $bytes = $fiscal->cliente()->pdf((string) $row->tipo_doc, (string) $row->numero_doc, $fiscal->config()['serie']);
                DB::update("UPDATE {$tabla} SET pdf_fiscal = decode(?, 'base64') WHERE id = ?", [base64_encode($bytes), $id]);
            } catch (Throwable) {
                $bytes = null;
            }
        }
        if ($bytes === null || $bytes === '') {
            abort(404, "Todavía no hay PDF oficial de esta {$nombre}.");
        }

        $nombreArchivo = strtolower($nombre).'-'.preg_replace('/[^0-9A-Za-z-]/', '', (string) ($row->numero_doc ?? $row->numero_control ?? $id)).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nombreArchivo.'"',
        ]);
    }
}