<?php

namespace App\Services\Tfhka;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de la API v2 de The Factory HKA Venezuela (Imprenta Digital VE).
 *
 *   $c = new TfhkaClient($baseUrl, $usuario, $clave, $timeout);
 *   $c->emitir($documento);              // POST /api/Emision
 *   $c->anular([...]);                   // POST /api/Anular
 *   $c->pdf('01', '00000123');           // POST /api/DescargaArchivo → base64
 *
 * El token JWT se guarda en caché hasta 15 minutos antes de vencer, para no
 * autenticar en cada venta. Si la API responde 401 se renueva una vez.
 */
class TfhkaClient
{
    private ?string $token = null;

    public function __construct(
        private string $baseUrl,
        private string $usuario,
        private string $clave,
        private int $timeout = 60,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /** Autentica y devuelve el token; usa caché por usuario + ambiente. */
    public function autenticar(bool $forzar = false): string
    {
        $key = 'tfhka:token:'.sha1($this->baseUrl.'|'.$this->usuario);
        if (! $forzar) {
            $guardado = Cache::get($key);
            if (is_array($guardado) && ($guardado['exp'] ?? 0) > time() + 900 && ! empty($guardado['token'])) {
                return $this->token = $guardado['token'];
            }
        }

        $r = Http::timeout($this->timeout)->acceptJson()
            ->post($this->baseUrl.'/api/Autenticacion', [
                'usuario' => $this->usuario,
                'clave' => $this->clave,
            ]);

        $json = $this->json($r, 'No se pudo autenticar con TFHKA');
        if (empty($json['token'])) {
            throw new TfhkaException($json['mensaje'] ?? 'TFHKA no devolvió un token de autenticación.');
        }

        $exp = isset($json['expiracion']) ? strtotime((string) $json['expiracion']) : time() + 12 * 3600;
        $ttl = max(60, $exp - time() - 900);
        Cache::put($key, ['token' => $json['token'], 'exp' => $exp], $ttl);

        return $this->token = $json['token'];
    }

    /** Emite un documento electrónico. Devuelve la respuesta completa (codigo, resultado, mensaje). */
    public function emitir(array $documento): array
    {
        return $this->post('/api/Emision', ['documentoElectronico' => $documento]);
    }

    /** Anula un documento. $datos: serie, tipoDocumento, numeroDocumento, motivoAnulacion, fechaAnulacion, horaAnulacion. */
    public function anular(array $datos): array
    {
        return $this->post('/api/Anular', [
            'Serie' => (string) ($datos['serie'] ?? ''),
            'TipoDocumento' => (string) $datos['tipoDocumento'],
            'NumeroDocumento' => (string) $datos['numeroDocumento'],
            'MotivoAnulacion' => (string) $datos['motivoAnulacion'],
            'FechaAnulacion' => (string) $datos['fechaAnulacion'],
            'HoraAnulacion' => (string) $datos['horaAnulacion'],
        ]);
    }

    /** Descarga el PDF oficial y devuelve los bytes. */
    public function pdf(string $tipoDocumento, string $numeroDocumento, string $serie = ''): string
    {
        $res = $this->post('/api/DescargaArchivo', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);

        $b64 = $res['archivo'] ?? $res['Archivo'] ?? null;
        if (! is_string($b64) || $b64 === '') {
            throw new TfhkaException($res['mensaje'] ?? 'TFHKA no devolvió el archivo PDF.');
        }

        $bytes = base64_decode(preg_replace('/^data:.*?;base64,/', '', $b64), true);
        if ($bytes === false || $bytes === '') {
            throw new TfhkaException('TFHKA devolvió un PDF ilegible.');
        }

        return $bytes;
    }

    /** Consulta el estado de un documento electrónico. */
    public function estado(string $tipoDocumento, string $numeroDocumento, string $serie = ''): array
    {
        return $this->post('/api/EstadoDocumento', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);
    }

    /** Consulta los rangos de numeración asignados a la empresa (diagnóstico). */
    public function numeraciones(string $tipoDocumento = '01', string $serie = ''): array
    {
        return $this->post('/api/ConsultaNumeraciones', [
            'TipoDocumento' => $tipoDocumento,
            'Serie' => $serie,
        ]);
    }

    /* ───────── Endpoints completos de la API v2 (swagger demoemisionv2) ───────── */

    /** Consulta el Último documento electrónico emitido (por tipo y serie). */
    public function ultimoDocumento(string $tipoDocumento = '01', string $serie = ''): array
    {
        return $this->post('/api/UltimoDocumento', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
        ]);
    }

    /** Listado de documentos anulados en un rango de fechas. */
    public function listadoAnulados(string $fechaDesde, string $fechaHasta): array
    {
        return $this->post('/api/ListadoDocumentos', [
            'FechaDesde' => $fechaDesde,
            'FechaHasta' => $fechaHasta,
        ]);
    }

    /** Asignación de Números de Control por rangos (para emisores autorizados). */
    public function asignarNumeraciones(array $datos): array
    {
        return $this->post('/api/AsignarNumeraciones', $datos);
    }

    /** Consulta de asignaciones de números de control por rangos de fecha. */
    public function listarAsignaciones(array $datos): array
    {
        return $this->post('/api/ListadoAsignaciones', $datos);
    }

    /** Consulta paginada de reservaciones de números de control. */
    public function consultaReservaciones(array $datos): array
    {
        return $this->post('/api/ConsultaReservaciones', $datos);
    }

    /** Registra un documento de CONTINGENCIA con su respaldo (PDF/imagen en base64). */
    public function contingenciaRegistrar(array $datos): array
    {
        return $this->post('/api/Contingencia/Registrar', $datos);
    }

    /** Listado de documentos de contingencia en un rango de fechas. */
    public function contingenciaListado(array $datos): array
    {
        return $this->post('/api/Contingencia/Listado', $datos);
    }

    /** Descarga el respaldo (base64) de un documento de contingencia. */
    public function contingenciaDescargar(array $datos): array
    {
        return $this->post('/api/Contingencia/Descargar', $datos);
    }

    /** Exporta a CSV (base64) los documentos de contingencia del rango. */
    public function contingenciaExportarCsv(array $datos): array
    {
        return $this->post('/api/Contingencia/ExportarCSV', $datos);
    }

    /** Envía por correo el PDF del documento electrónico. */
    public function correoEnviar(string $tipoDocumento, string $numeroDocumento, array $destinatarios, string $serie = ''): array
    {
        return $this->post('/api/Correo/Enviar', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
            'Destinatarios' => $destinatarios,
        ]);
    }

    /** Rastreo del envío por correo de un documento. */
    public function correoRastreo(string $tipoDocumento, string $numeroDocumento, string $serie = ''): array
    {
        return $this->post('/api/Correo/Rastreo', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);
    }

    /** Aplica o actualiza la Retención de IVA sobre un documento electrónico. */
    public function aplicarRetencion(array $datos): array
    {
        return $this->post('/api/AplicarRetencion', $datos);
    }

    /** Elimina la Retención de IVA registrada sobre un documento. */
    public function eliminarRetencion(array $datos): array
    {
        return $this->borrar('/api/AplicarRetencion', $datos);
    }

    /** Consulta la Retención de IVA registrada sobre un documento. */
    public function retencion(string $tipoDocumento, string $numeroDocumento, string $serie = ''): array
    {
        return $this->post('/api/AplicarRetencion', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);
    }

    /** Aplica o actualiza la Retención ISLR sobre un documento electrónico. */
    public function aplicarRetencionIslr(array $datos): array
    {
        return $this->post('/api/AplicarRetencionISLR', $datos);
    }

    /** Elimina la Retención ISLR registrada sobre un documento. */
    public function eliminarRetencionIslr(array $datos): array
    {
        return $this->borrar('/api/AplicarRetencionISLR', $datos);
    }

    /** Consulta la Retención ISLR registrada sobre un documento. */
    public function retencionIslr(string $tipoDocumento, string $numeroDocumento, string $serie = ''): array
    {
        return $this->post('/api/AplicarRetencionISLR', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);
    }

    /** Emisión de comprobante ARC. */
    public function emitirArc(array $documento): array
    {
        return $this->post('/api/EmisionARC', ['documentoElectronico' => $documento]);
    }

    /** Emisión de Constancia de Recepción de Mercancía (EmisionDNF). */
    public function constanciaRecepcion(array $datos): array
    {
        return $this->post('/api/EmisionDNF/constanciaRecepcion', $datos);
    }

    /** Estado de un LOTE de documentos electrónicos. */
    public function estadoLote(string $idLote): array
    {
        return $this->post('/api/EstadoLote', ['IdLote' => $idLote]);
    }

    /** Envía una facturación por lote (lista de compradores). */
    public function facturacionLoteEnviar(array $datos): array
    {
        return $this->post('/api/FacturacionLote/Enviar', $datos);
    }

    /** Estado de una facturación por lote. */
    public function facturacionLoteEstado(string $idLote): array
    {
        return $this->post('/api/FacturacionLote/Estado', ['IdLote' => $idLote]);
    }

    /** Sube un lote (.zip base64) al SFTP de la imprenta. */
    public function subirLote(array $datos): array
    {
        return $this->post('/api/SubirLote', $datos);
    }

    /** Devuelve el archivo de resultados (CSV base64) de un lote procesado. */
    public function resultadoLote(array $datos): array
    {
        return $this->post('/api/ResultadoLote', $datos);
    }

    /** Información de relación del documento (Factura ↔ Guía/Nota de Entrega). */
    public function relacionDocumento(string $tipoDocumento, string $numeroDocumento, string $serie = ''): array
    {
        return $this->post('/api/Documentos/Relacionar', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);
    }

    /** Aplica o actualiza la relación del documento (Factura ↔ Guía/Nota de Entrega). */
    public function relacionar(array $datos): array
    {
        return $this->post('/api/Documentos/Relacionar', $datos);
    }

    /** Elimina toda la relación del documento informado. */
    public function eliminarRelacion(string $tipoDocumento, string $numeroDocumento, string $serie = ''): array
    {
        return $this->borrar('/api/Documentos/Relacionar', [
            'Serie' => $serie,
            'TipoDocumento' => $tipoDocumento,
            'NumeroDocumento' => $numeroDocumento,
        ]);
    }

    /** Rastreo de órdenes de envío (Correo/EnviaOrden). */
    public function correoRastreoOrden(array $datos): array
    {
        return $this->post('/api/Correo/RastreoOrden', $datos);
    }

    private function borrar(string $path, array $payload): array
    {
        $token = $this->token ?: $this->autenticar();
        $r = Http::timeout($this->timeout)->acceptJson()
            ->withToken($token)
            ->delete($this->baseUrl.$path, $payload);
        if ($r->status() === 401) {
            $r = Http::timeout($this->timeout)->acceptJson()
                ->withToken($this->autenticar(true))
                ->delete($this->baseUrl.$path, $payload);
        }

        return $this->json($r, 'TFHKA rechazó la eliminación');
    }

    private function post(string $path, array $payload): array
    {
        $token = $this->token ?: $this->autenticar();
        $r = $this->enviar($path, $payload, $token);

        if ($r->status() === 401) {
            $token = $this->autenticar(true);
            $r = $this->enviar($path, $payload, $token);
        }

        return $this->json($r, 'TFHKA rechazó la operación');
    }

    private function enviar(string $path, array $payload, string $token)
    {
        return Http::timeout($this->timeout)->acceptJson()
            ->withToken($token)
            ->post($this->baseUrl.$path, $payload);
    }

    /** Normaliza la respuesta: exige codigo 200 y convierte errores en TfhkaException. */
    private function json($r, string $porDefecto): array
    {
        $json = $r->json();
        if (! is_array($json)) {
            $json = [];
        }

        $codigo = isset($json['codigo']) ? (string) $json['codigo'] : null;
        if (! $r->successful() || ($codigo !== null && $codigo !== '200')) {
            $msg = $json['mensaje'] ?? $json['message'] ?? $porDefecto;
            if (isset($json['errores']) && is_array($json['errores'])) {
                $msg .= ' '.implode(' ', array_map('strval', $json['errores']));
            }
            if ($r->status() === 401) {
                $msg = 'Credenciales de TFHKA rechazadas o token vencido. Revisa usuario y clave.';
            }

            throw new TfhkaException(trim($msg));
        }

        return $json;
    }
}