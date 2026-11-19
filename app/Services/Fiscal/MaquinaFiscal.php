<?php

namespace App\Services\Fiscal;

use App\Services\Tfhka\TfhkaException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Modo «Máquina fiscal»: el sistema envía el documento a la impresora fiscal
 * autorizada por el SENIAT y registra el número de factura y el número de
 * control que la máquina reporta. Tres protocolos:
 *
 *   TCP       → socket directo (punto de emisión de la máquina, típ. 9100). Se
 *               construye el texto del documento y se extrae de la respuesta el
 *               par «factura–control» en el formato clásico
 *               NNNNNNNN-NNNNNNNN-0000000000000000 (8 dígitos de la factura,
 *               8 del registro de la máquina y 14 en ceros).
 *   HTTP      → driver local que expone la máquina por HTTP (por ejemplo el
 *               agente del proveedor en el mismo equipo): POST JSON con el
 *               documento y respuesta JSON {factura, control}.
 *   SIMULADOR → no usa hardware: asigna el correlativo local con el formato
 *               de máquina fiscal. Sirve para pruebas e integraciones sin la
 *               impresora (así se prueban toda la emisión, notas y anulaciones).
 *
 * El modo se guarda en facturacion_config (SQL 43): IMPRENTA_DIGITAL,
 * MAQUINA_FISCAL o MANUAL. La imprenta digital (TFHKA) sigue siendo el camino
 * principal; la máquina fiscal es el segundo modo que exige el SENIAT para
 * equipos autorizados.
 */
class MaquinaFiscal
{
    private ?array $cfg = null;

    public function __construct()
    {
        try {
            $this->cfg = (array) DB::selectOne('SELECT * FROM facturacion_config WHERE id = 1');
        } catch (Throwable) {
            $this->cfg = null; // sin SQL 43: el modo no está disponible
        }
    }

    /** Modo actual de emisión fiscal. Si falta la tabla, se deduce del legado. */
    public function modo(): string
    {
        if ($this->cfg === null || empty($this->cfg)) {
            return 'IMPRENTA_DIGITAL'; // comportamiento por defecto como siempre
        }

        return (string) ($this->cfg['modo'] ?? 'IMPRENTA_DIGITAL');
    }

    public function config(): array
    {
        $c = $this->cfg ?: [];
        return [
            'modo' => $this->modo(),
            'maquina_registro' => $c['maquina_registro'] ?? null,
            'maquina_ip' => $c['maquina_ip'] ?? null,
            'maquina_puerto' => (int) ($c['maquina_puerto'] ?? 9100),
            'maquina_protocolo' => $c['maquina_protocolo'] ?? 'TCP',
            'punto_emision' => $c['punto_emision'] ?? null,
            'oficina' => $c['oficina'] ?? null,
            'serie' => $c['serie'] ?? null,
            'activo' => (bool) ($c['activo'] ?? true),
        ];
    }

    /** Guarda modo y datos de la máquina. Devuelve el arreglo público. */
    public function guardar(array $d): array
    {
        if ($this->cfg === null || empty($this->cfg)) {
            throw new TfhkaException('Falta ejecutar database/sql/43_facturacion_modos.sql en la base de datos.');
        }

        $modo = strtoupper((string) ($d['modo'] ?? ''));
        if (! in_array($modo, ['IMPRENTA_DIGITAL', 'MAQUINA_FISCAL', 'MANUAL'], true)) {
            throw new TfhkaException('El modo de facturación debe ser IMPRENTA_DIGITAL, MAQUINA_FISCAL o MANUAL.');
        }

        if ($modo === 'MAQUINA_FISCAL') {
            $proto = strtoupper((string) ($d['maquina_protocolo'] ?? 'TCP'));
            if (! in_array($proto, ['TCP', 'HTTP', 'SIMULADOR'], true)) {
                throw new TfhkaException('El protocolo de la máquina fiscal debe ser TCP, HTTP o SIMULADOR.');
            }
            if (in_array($proto, ['TCP', 'HTTP'], true) && trim((string) ($d['maquina_ip'] ?? '')) === '') {
                throw new TfhkaException('Indica la dirección (IP/host) de la máquina fiscal o de su driver, o usa el protocolo SIMULADOR para pruebas.');
            }
        }

        DB::table('facturacion_config')->where('id', 1)->update([
            'modo' => $modo,
            'maquina_registro' => trim((string) ($d['maquina_registro'] ?? '')) ?: null,
            'maquina_ip' => trim((string) ($d['maquina_ip'] ?? '')) ?: null,
            'maquina_puerto' => (int) ($d['maquina_puerto'] ?? 9100),
            'maquina_protocolo' => strtoupper((string) ($d['maquina_protocolo'] ?? 'TCP')),
            'punto_emision' => trim((string) ($d['punto_emision'] ?? '')) ?: null,
            'oficina' => trim((string) ($d['oficina'] ?? '')) ?: null,
            'serie' => trim((string) ($d['serie'] ?? '')) ?: null,
            'updated_at' => now(),
        ]);

        $this->cfg = (array) DB::selectOne('SELECT * FROM facturacion_config WHERE id = 1');

        return $this->config();
    }

    /** ¿La máquina está lista para emitir? (modo MAQUINA_FISCAL con datos coherentes) */
    public function activo(): bool
    {
        if ($this->modo() !== 'MAQUINA_FISCAL' || ! ($this->cfg['activo'] ?? true)) {
            return false;
        }

        $proto = $this->cfg['maquina_protocolo'] ?? 'TCP';
        if ($proto === 'SIMULADOR') {
            return true;
        }

        return trim((string) ($this->cfg['maquina_ip'] ?? '')) !== '';
    }

    /** Prueba de comunicación con la máquina o su driver. */
    public function probar(): array
    {
        $proto = $this->cfg['maquina_protocolo'] ?? 'TCP';
        try {
            if ($proto === 'SIMULADOR') {
                return ['ok' => true, 'mensaje' => 'Simulador listo: asigna números de control con el formato de máquina fiscal (para pruebas).'];
            }
            if ($proto === 'HTTP') {
                $url = $this->urlDriver();
                $r = \Illuminate\Support\Facades\Http::timeout(8)->asJson()->post($url, ['accion' => 'ping']);
                $ok = $r->successful();

                return ['ok' => $ok, 'mensaje' => $ok ? 'Driver HTTP de la máquina fiscal respondió.' : 'El driver HTTP no respondió (HTTP '.$r->status().').'];
            }
            $sock = @fsockopen($this->cfg['maquina_ip'], (int) $this->cfg['maquina_puerto'], $errno, $errstr, 8);
            if ($sock) {
                fclose($sock);

                return ['ok' => true, 'mensaje' => 'Conexión TCP abierta con la máquina fiscal.'];
            }

            return ['ok' => false, 'mensaje' => "No se pudo abrir TCP con {$this->cfg['maquina_ip']}:{$this->cfg['maquina_puerto']} ($errstr)."];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'Prueba de máquina fiscal falló: '.$e->getMessage()];
        }
    }

    /**
     * Imprime la factura en la máquina y devuelve ['factura' => '…', 'control' => '…'].
     * $datos es el arreglo de DatosDocumento->venta().
     */
    public function imprimirFactura(array $datos): array
    {
        return $this->emitir($this->textoDocumento($datos, '01'), '01', 'FACTURA');
    }

    /** Imprime una Nota de Crédito (02) o de Débito (03) en la máquina. */
    public function imprimirNota(array $datos, string $tipo): array
    {
        $etiqueta = $tipo === 'C' ? 'NOTA DE CREDITO' : 'NOTA DE DEBITO';

        return $this->emitir($this->textoDocumento($datos, $tipo === 'C' ? '02' : '03'), $tipo === 'C' ? '02' : '03', $etiqueta);
    }

    private function emitir(string $comando, string $tipoDoc, string $etiqueta): array
    {
        $proto = $this->cfg['maquina_protocolo'] ?? 'TCP';
        try {
            $res = match ($proto) {
                'SIMULADOR' => $this->simulador($tipoDoc),
                'HTTP' => $this->viaHttp($comando),
                default => $this->viaTcp($comando),
            };
        } catch (TfhkaException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TfhkaException('La máquina fiscal no respondió: '.$e->getMessage());
        }

        ['factura' => $factura, 'control' => $control] = $res;

        if ($proto !== 'SIMULADOR') {
            // Bitácora: lo que la máquina imprimió queda registrado para auditoría.
            $this->bitacora('EMITIR', $tipoDoc, $factura, 'OK', "Impreso en máquina fiscal ($etiqueta)", ['respuesta' => $res]);
        }

        return ['factura' => $factura, 'control' => $control];
    }

    /** SIMULADOR: correlativo local con formato de máquina fiscal. */
    private function simulador(string $tipoDoc): array
    {
        $col = $tipoDoc === '01' ? 'siguiente_factura' : 'siguiente_nota';
        $n = (int) DB::transaction(function () use ($col) {
            $row = DB::selectOne('SELECT '.$col.' AS n, maquina_registro FROM facturacion_config WHERE id = 1 FOR UPDATE');
            $sig = (int) $row->n + 1;
            DB::update('UPDATE facturacion_config SET '.$col.' = ?, updated_at = now() WHERE id = 1', [$sig]);

            return (int) $row->n;
        });
        $factura = str_pad((string) $n, 8, '0', STR_PAD_LEFT);
        $registro = $this->registroOcho();

        return ['factura' => $factura, 'control' => $this->formatoControl($factura, $registro)];
    }

    /** TCP: abre el socket, manda el documento y lee la respuesta de la máquina. */
    private function viaTcp(string $comando): array
    {
        $ip = trim((string) $this->cfg['maquina_ip']);
        $puerto = (int) $this->cfg['maquina_puerto'];
        $sock = @fsockopen($ip, $puerto, $errno, $errstr, 10);
        if (! $sock) {
            throw new TfhkaException("No se pudo conectar con la máquina fiscal en {$ip}:{$puerto} ($errstr).");
        }
        stream_set_timeout($sock, 15);
        fwrite($sock, $comando);
        $respuesta = '';
        while (! feof($sock)) {
            $parte = fgets($sock, 2048);
            if ($parte === false) {
                break;
            }
            $respuesta .= $parte;
            if (preg_match('/(\d{8})\s*[- ]\s*(\d{8})\s*[- ]\s*(\d{14})/', $respuesta)) {
                break; // ya llegó el par factura-control que buscamos
            }
        }
        fclose($sock);
        $par = $this->extraerPar($respuesta);
        if ($par === null) {
            throw new TfhkaException('La máquina fiscal respondió sin número de factura/control legible. Respuesta: '.mb_substr(trim($respuesta), 0, 200));
        }

        return $par;
    }

    /** HTTP: el driver local de la máquina recibe JSON y responde {factura, control}. */
    private function viaHttp(string $comando): array
    {
        $r = \Illuminate\Support\Facades\Http::timeout(15)->asJson()->post($this->urlDriver(), [
            'accion' => 'imprimir',
            'documento' => $comando,
        ]);
        if (! $r->successful()) {
            throw new TfhkaException('El driver HTTP de la máquina fiscal respondió HTTP '.$r->status().'.');
        }
        $j = (array) ($r->json() ?? []);
        $factura = (string) ($j['factura'] ?? $j['Factura'] ?? '');
        $control = (string) ($j['control'] ?? $j['Control'] ?? '');
        if ($factura === '' || $control === '') {
            $par = $this->extraerPar((string) ($j['respuesta'] ?? json_encode($j)));
            if ($par === null) {
                throw new TfhkaException('El driver HTTP no devolvió factura/control legibles.');
            }

            return $par;
        }

        return ['factura' => $factura, 'control' => $control];
    }

    private function urlDriver(): string
    {
        $ip = trim((string) $this->cfg['maquina_ip']);
        if (! preg_match('#^https?://#i', $ip)) {
            $ip = 'http://'.$ip;
        }

        return rtrim($ip, '/').':'.(int) $this->cfg['maquina_puerto'].'/fiscal';
    }

    /** Extrae el par «factura-control» de una respuesta de máquina. */
    private function extraerPar(string $respuesta): ?array
    {
        if (preg_match('/(\d{8})\s*[- ]\s*(\d{8})\s*[- ]\s*(\d{14})/', $respuesta, $m)) {
            return ['factura' => $m[1], 'control' => $m[1].'-'.$m[2].'-'.$m[3]];
        }
        if (preg_match('/(?:factura|FAC)[^\d]{0,6}(\d{1,8})/i', $respuesta, $m)) {
            $factura = str_pad($m[1], 8, '0', STR_PAD_LEFT);

            return ['factura' => $factura, 'control' => $this->formatoControl($factura, $this->registroOcho())];
        }

        return null;
    }

    /** Número de registro de la máquina normalizado a 8 dígitos (si es numérico). */
    private function registroOcho(): string
    {
        $reg = preg_replace('/\D/', '', (string) ($this->cfg['maquina_registro'] ?? ''));
        if ($reg !== '') {
            return str_pad(substr($reg, -8), 8, '0', STR_PAD_LEFT);
        }

        return '00000000';
    }

    /** Formato clásico del número de control de máquina fiscal: 8+8+14 = 30 dígitos. */
    private function formatoControl(string $factura8, string $registro8): string
    {
        return $factura8.'-'.$registro8.'-0000000000000000';
    }

    /** Texto del documento para el protocolo TCP de la máquina (una línea por campo).
     *  Tolerante a la estructura de venta (con líneas) y de nota (solo totales). */
    private function textoDocumento(array $datos, string $tipoDoc): string
    {
        $l = [];
        $l[] = "@DN|{$tipoDoc}";
        $rif = $datos['emisor']['rif'] ?? ($datos['venta']['emisor']['rif'] ?? '');
        $razon = $datos['emisor']['razon_social'] ?? ($datos['venta']['emisor']['razon_social'] ?? '');
        $l[] = '@RI|'.(string) $rif;
        $l[] = '@NN|'.(string) $razon;
        if (! empty($datos['cliente']['documento'])) {
            $l[] = '@CI|'.($datos['cliente']['documento']);
            $l[] = '@CN|'.($datos['cliente']['nombre'] ?? '');
        }
        foreach ($datos['lineas'] ?? [] as $li) {
            $precio = $li['precio_usd'] ?? ($li['precio_unitario_usd'] ?? 0);
            $l[] = sprintf('@I|1|%s|%s|%0.2f|%s', $li['sku'] ?? '', mb_substr($li['descripcion'] ?? 'Item', 0, 40), (float) $precio, $li['alicuota'] === 'E' ? 'E' : ($li['alicuota'] === 'F' ? 'X' : 'G'));
        }
        $total = $datos['totales']['total_cobrar_usd'] ?? ($datos['totales']['total_usd'] ?? ($datos['total_ves'] ?? 0));
        $l[] = sprintf('@T|%0.2f', (float) $total);
        $l[] = '@PE|'.($this->cfg['punto_emision'] ?? '01');

        return implode("\n", $l)."\n";
    }

    private function bitacora(string $operacion, string $tipoDoc, string $numero, string $estado, string $mensaje, array $extra = []): void
    {
        try {
            $emisor = new \App\Services\Tfhka\EmisorFiscal();
            if (method_exists($emisor, 'logBitacora')) {
                $emisor->logBitacora(array_merge([
                    'operacion' => $operacion, 'tipo_documento' => $tipoDoc, 'numero_documento' => $numero,
                    'estado' => $estado, 'mensaje' => $mensaje,
                ], $extra));
            }
        } catch (Throwable $e) {
            Log::warning('Máquina fiscal: no se pudo escribir la bitácora: '.$e->getMessage());
        }
    }
}
