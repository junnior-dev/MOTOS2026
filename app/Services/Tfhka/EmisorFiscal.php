<?php

namespace App\Services\Tfhka;

use App\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquesta la facturación digital de una tienda:
 *   · lee su configuración (credenciales cifradas por negocio),
 *   · arma el documento con DocumentoFiscalBuilder,
 *   · llama a TFHKA, guarda control/URL/PDF y deja bitácora en tfhka_eventos.
 *
 * La venta y la nota ya están registradas localmente cuando se llama aquí: un
 * fallo de la API nunca pierde la operación, solo deja el documento PENDIENTE o
 * en ERROR para reintentar.
 */
class EmisorFiscal
{
    private ?array $cfg = null;

    private ?TfhkaClient $cliente = null;

    private ?\App\Services\Fiscal\MaquinaFiscal $maquina = null;

    private DocumentoFiscalBuilder $builder;

    public function __construct()
    {
        $this->builder = new DocumentoFiscalBuilder();
    }

    /* ───────────── Configuración ───────────── */

    public function tablaLista(): bool
    {
        try {
            return (bool) DB::scalar("SELECT to_regclass('tfhka_config') IS NOT NULL");
        } catch (Throwable) {
            return false;
        }
    }

    public function config(): array
    {
        if ($this->cfg !== null) {
            return $this->cfg;
        }

        $negocioId = Tenant::id() ?? (int) DB::scalar('SELECT fn_negocio_actual()');
        $row = null;
        if ($this->tablaLista()) {
            try {
                $row = DB::selectOne('SELECT * FROM tfhka_config WHERE negocio_id = ?', [$negocioId]);
            } catch (Throwable $e) {
                Log::warning('TFHKA: no se pudo leer tfhka_config: '.$e->getMessage());
            }
        }

        $env = (array) config('tfhka');
        $base = trim((string) ($row->base_url ?? $env['base_url'] ?? ''));
        $usuario = trim((string) ($row->usuario ?? ''));
        $clave = '';

        if ($row && ! empty($row->clave_cifrada)) {
            try {
                $clave = Crypt::decryptString($row->clave_cifrada);
            } catch (Throwable $e) {
                Log::warning('TFHKA: la clave guardada no se pudo descifrar (¿cambió APP_KEY?).');
            }
        }

        // Sin fila o sin usuario propio: se usan las credenciales del .env.
        if ($usuario === '') {
            $usuario = trim((string) ($env['usuario'] ?? ''));
            $clave = (string) ($env['clave'] ?? '');
        } elseif ($clave === '' && $usuario === trim((string) ($env['usuario'] ?? ''))) {
            $clave = (string) ($env['clave'] ?? '');
        }

        $ambiente = strtoupper((string) ($row->ambiente ?? 'DEMO'));
        if ($base === '') {
            $base = $ambiente === 'PRODUCCION'
                ? (string) ($env['produccion_url'] ?? 'https://emision.thefactoryhka.com.ve')
                : (string) ($env['demo_url'] ?? 'https://demoemisionv2.thefactoryhka.com.ve');
        }

        $this->cfg = [
            'negocio_id' => $negocioId,
            'activo' => (bool) ($row->activo ?? false),
            'ambiente' => $ambiente === 'PRODUCCION' ? 'PRODUCCION' : 'DEMO',
            'base_url' => rtrim($base, '/'),
            'usuario' => $usuario,
            'clave' => $clave,
            'serie' => (string) ($row->serie ?? ''),
            'sucursal' => (string) ($row->sucursal ?? ''),
            'codigo_ciiu' => (string) ($row->codigo_ciiu ?? ($env['codigo_ciiu'] ?? '4663')),
            'codigo_plu_default' => (string) ($row->codigo_plu_default ?? ''),
            'usar_datos_negocio' => (bool) ($row->usar_datos_negocio ?? true),
            'auto_emitir' => (bool) ($row->auto_emitir ?? ($env['auto_emitir'] ?? true)),
            'timeout' => (int) ($row->timeout_segundos ?? ($env['timeout'] ?? 60)),
            'timeout_segundos' => (int) ($row->timeout_segundos ?? ($env['timeout'] ?? 60)),
            'moneda' => (string) ($row->moneda ?? ($env['moneda'] ?? 'BSD')),
            'regimen_esp_tributacion' => (string) ($row->regimen_esp_tributacion ?? ''),
            'guardar_pdf' => (bool) ($env['guardar_pdf'] ?? true),
            'demo_url' => (string) ($env['demo_url'] ?? 'https://demoemisionv2.thefactoryhka.com.ve'),
            'produccion_url' => (string) ($env['produccion_url'] ?? 'https://emision.thefactoryhka.com.ve'),
        ];

        return $this->cfg;
    }

    /** Configuración apta para el navegador: nunca incluye la clave. */
    public function configPublica(): array
    {
        $c = $this->config();
        unset($c['clave']);

        $c['tiene_clave'] = (bool) ($this->config()['clave'] !== '');
        $c['tabla_lista'] = $this->tablaLista();
        $c['ultima_prueba_en'] = null;
        $c['ultima_prueba_ok'] = null;
        $c['ultima_prueba_mensaje'] = null;

        if ($this->tablaLista()) {
            try {
                $row = DB::selectOne('SELECT ultima_prueba_en, ultima_prueba_ok, ultima_prueba_mensaje FROM tfhka_config WHERE negocio_id = ?', [$c['negocio_id']]);
                if ($row) {
                    $c['ultima_prueba_en'] = $row->ultima_prueba_en;
                    $c['ultima_prueba_ok'] = $row->ultima_prueba_ok === null ? null : (bool) $row->ultima_prueba_ok;
                    $c['ultima_prueba_mensaje'] = $row->ultima_prueba_mensaje;
                }
            } catch (Throwable) {
                // sin tabla o sin fila: se devuelve lo que haya
            }
        }

        return $c;
    }

    public function activo(): bool
    {
        $m = $this->maquina();
        if ($m->modo() === 'MAQUINA_FISCAL') {
            return $m->activo();
        }
        if ($m->modo() === 'MANUAL') {
            return false; // numeración local del negocio, sin emisor automático
        }
        $c = $this->config();

        return $c['activo'] && $c['usuario'] !== '' && $c['clave'] !== '';
    }

    public function autoEmitir(): bool
    {
        $m = $this->maquina();
        if ($m->modo() === 'MAQUINA_FISCAL') {
            return $m->activo(); // todo lo cobrado se imprime en la máquina
        }
        if ($m->modo() === 'MANUAL') {
            return false;
        }

        return $this->activo() && (bool) $this->config()['auto_emitir'];
    }

    /** Servicio del modo «máquina fiscal» (SQL 43); comparte el modo de emisión del negocio. */
    public function maquina(): \App\Services\Fiscal\MaquinaFiscal
    {
        return $this->maquina ?: $this->maquina = new \App\Services\Fiscal\MaquinaFiscal();
    }

    /** Bitácora compartida para todos los modos de emisión (imprenta digital y máquina fiscal). */
    public function logBitacora(array $d): void
    {
        $this->evento($d);
    }

    /** Guarda la configuración de la tienda. La clave solo se cifra si viene informada. */
    public function guardarConfig(array $d, ?int $usuarioId = null): array
    {
        if (! $this->tablaLista()) {
            throw new TfhkaException('Falta ejecutar database/sql/40_tfhka_facturacion.sql en la base de datos.');
        }

        $negocioId = Tenant::id() ?? (int) DB::scalar('SELECT fn_negocio_actual()');
        $antes = DB::selectOne('SELECT * FROM tfhka_config WHERE negocio_id = ?', [$negocioId]);

        $ambiente = strtoupper((string) ($d['ambiente'] ?? 'DEMO')) === 'PRODUCCION' ? 'PRODUCCION' : 'DEMO';
        $base = trim((string) ($d['base_url'] ?? ''));
        if ($base === '') {
            $base = $ambiente === 'PRODUCCION' ? $this->config()['produccion_url'] : $this->config()['demo_url'];
        }

        $campos = [
            'activo' => (bool) ($d['activo'] ?? false),
            'ambiente' => $ambiente,
            'base_url' => rtrim($base, '/'),
            'usuario' => trim((string) ($d['usuario'] ?? '')),
            'serie' => trim((string) ($d['serie'] ?? '')),
            'sucursal' => trim((string) ($d['sucursal'] ?? '')),
            'codigo_ciiu' => trim((string) ($d['codigo_ciiu'] ?? '4663')),
            'codigo_plu_default' => trim((string) ($d['codigo_plu_default'] ?? '')),
            'usar_datos_negocio' => (bool) ($d['usar_datos_negocio'] ?? true),
            'auto_emitir' => (bool) ($d['auto_emitir'] ?? true),
            'timeout_segundos' => max(5, min(300, (int) ($d['timeout_segundos'] ?? 60))),
            'moneda' => strtoupper(trim((string) ($d['moneda'] ?? 'BSD'))),
            'regimen_esp_tributacion' => trim((string) ($d['regimen_esp_tributacion'] ?? '')),
            'updated_at' => now(),
        ];

        $clave = trim((string) ($d['clave'] ?? ''));
        if ($clave !== '') {
            $campos['clave_cifrada'] = Crypt::encryptString($clave);
        }

        if ($antes) {
            DB::table('tfhka_config')->where('negocio_id', $negocioId)->update($campos);
        } else {
            $campos['negocio_id'] = $negocioId;
            DB::table('tfhka_config')->insert($campos);
        }

        // El token viejo ya no sirve si cambiaron usuario, clave o ambiente.
        foreach ([$antes, (object) $campos] as $r) {
            if (! $r) {
                continue;
            }
            $u = trim((string) ($r->usuario ?? ''));
            $b = rtrim((string) ($r->base_url ?? ''), '/');
            if ($u !== '' && $b !== '') {
                Cache::forget('tfhka:token:'.sha1($b.'|'.$u));
            }
        }

        $this->cfg = null;
        $this->cliente = null;

        $this->evento([
            'operacion' => 'PRUEBA',
            'estado' => 'OK',
            'mensaje' => 'Configuración de facturación digital guardada.',
            'usuario_id' => $usuarioId,
        ]);

        return $this->configPublica();
    }

    /** Autentica contra TFHKA y deja constancia del resultado (no emite nada). */
    public function probar(?int $usuarioId = null): array
    {
        // En modo máquina fiscal la prueba es de comunicación con la impresora o su driver.
        if ($this->maquina()->modo() === 'MAQUINA_FISCAL') {
            $p = $this->maquina()->probar();
            $this->logBitacora([
                'operacion' => 'PRUEBA', 'estado' => $p['ok'] ? 'OK' : 'ERROR', 'mensaje' => $p['mensaje'], 'usuario_id' => $usuarioId,
            ]);
            if (! $p['ok']) {
                throw new TfhkaException($p['mensaje']);
            }

            return ['ok' => true, 'mensaje' => $p['mensaje'], 'token_vencimiento' => null];
        }

        $c = $this->config();
        if ($c['usuario'] === '' || $c['clave'] === '') {
            throw new TfhkaException('Escribe el usuario y la clave que te entregó TFHKA.');
        }

        try {
            $this->cliente()->autenticar(true);
            $msg = 'Credenciales aceptadas por TFHKA.';
            $ok = true;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $ok = false;
        }

        if ($this->tablaLista()) {
            try {
                DB::update('UPDATE tfhka_config SET ultima_prueba_en = now(), ultima_prueba_ok = ?, ultima_prueba_mensaje = ?, updated_at = now() WHERE negocio_id = ?', [$ok, mb_substr($msg, 0, 1000), $c['negocio_id']]);
            } catch (Throwable $e) {
                Log::warning('TFHKA: no se pudo guardar la prueba: '.$e->getMessage());
            }
        }

        $this->evento([
            'operacion' => 'PRUEBA',
            'estado' => $ok ? 'OK' : 'ERROR',
            'mensaje' => $msg,
            'usuario_id' => $usuarioId,
        ]);

        if (! $ok) {
            throw new TfhkaException($msg);
        }

        return ['ok' => true, 'mensaje' => $msg, 'token_vencimiento' => null];
    }

    public function cliente(): TfhkaClient
    {
        if ($this->cliente) {
            return $this->cliente;
        }
        $c = $this->config();
        if ($c['usuario'] === '' || $c['clave'] === '') {
            throw new TfhkaException('La facturación digital no tiene credenciales. Configúralas en Configuración → Facturación digital.');
        }

        return $this->cliente = new TfhkaClient($c['base_url'], $c['usuario'], $c['clave'], $c['timeout']);
    }

    /* ───────────── Facturas ───────────── */

    /**
     * Emite (o recupera) la factura fiscal de una venta ya registrada.
     * Idempotente: si la venta ya tiene control, no vuelve a llamar a TFHKA.
     */
    public function emitirVenta(int $ventaId, ?int $usuarioId = null, bool $forzar = false): array
    {
        if (! $this->activo()) {
            throw new TfhkaException('La facturación digital no está activa para esta tienda.');
        }

        $datos = (new DatosDocumento())->venta($ventaId);
        if (! $datos) {
            throw new TfhkaException('La venta no existe.');
        }
        if ($datos['estado'] !== 'COMPLETADA') {
            throw new TfhkaException('Una venta anulada no se puede facturar.');
        }
        if (! $forzar && $datos['tfhka_estado'] === 'EMITIDA' && ! empty($datos['control'])) {
            return $this->resultadoVenta($ventaId);
        }

        // Modo «máquina fiscal»: la impresora autorizada imprime y reporta el
        // número de factura y de control; el sistema los registra tal cual.
        if ($this->maquina()->modo() === 'MAQUINA_FISCAL') {
            $imp = $this->maquina()->imprimirFactura($datos);
            DB::statement('CALL sp_asignar_factura_fiscal(?, ?, ?)', [$ventaId, $imp['factura'], $imp['control']]);
            DB::update("UPDATE ventas SET tfhka_estado = 'EMITIDA', tfhka_emitida_en = now(), fuente_fiscal = 'MAQUINA_FISCAL' WHERE id = ?", [$ventaId]);
            $this->logBitacora([
                'venta_id' => $ventaId, 'operacion' => 'EMITIR', 'tipo_documento' => 'MA', 'numero_documento' => $imp['factura'],
                'estado' => 'OK', 'mensaje' => 'Factura impresa en máquina fiscal (control '.$imp['control'].').',
                'respuesta' => $imp, 'usuario_id' => $usuarioId,
            ]);

            return $this->resultadoVenta($ventaId);
        }

        $c = $this->config();
        $numero = (string) $datos['numero'];

        // Si un intento anterior quedó en duda (error de red), preguntar antes de reemitir.
        if (! $forzar) {
            $recuperado = $this->recuperarVenta($ventaId, $numero, $datos['fecha'], $usuarioId);
            if ($recuperado) {
                return $recuperado;
            }
        }

        $payload = $this->builder->factura($datos, $c);
        $this->evento([
            'venta_id' => $ventaId, 'operacion' => 'EMITIR', 'tipo_documento' => '01', 'numero_documento' => $numero,
            'estado' => 'OK', 'mensaje' => 'Enviando factura a TFHKA.', 'payload' => $payload, 'usuario_id' => $usuarioId,
        ]);

        try {
            $resp = $this->cliente()->emitir($payload);
        } catch (Throwable $e) {
            $this->marcarVenta($ventaId, null, null, 'ERROR', ['error' => $e->getMessage()], false, false);
            $this->evento([
                'venta_id' => $ventaId, 'operacion' => 'EMITIR', 'tipo_documento' => '01', 'numero_documento' => $numero,
                'estado' => 'ERROR', 'mensaje' => $e->getMessage(), 'payload' => $payload, 'usuario_id' => $usuarioId,
            ]);
            throw $e;
        }

        $res = $resp['resultado'] ?? $resp;
        $control = trim((string) ($res['numeroControl'] ?? ''));
        if ($control === '') {
            $msg = $resp['mensaje'] ?? 'TFHKA no devolvió número de control.';
            $this->marcarVenta($ventaId, null, null, 'ERROR', $resp, false, false);
            $this->evento([
                'venta_id' => $ventaId, 'operacion' => 'EMITIR', 'tipo_documento' => '01', 'numero_documento' => $numero,
                'estado' => 'ERROR', 'mensaje' => $msg, 'payload' => $payload, 'respuesta' => $resp, 'usuario_id' => $usuarioId,
            ]);
            throw new TfhkaException($msg);
        }

        $numeroFiscal = trim((string) ($res['numeroDocumento'] ?? $numero));
        $url = trim((string) ($res['urlConsulta'] ?? '')) ?: null;
        $this->marcarVenta($ventaId, $numeroFiscal, $control, 'EMITIDA', $resp, true, true);

        $pdfOk = $this->guardarPdfVenta($ventaId, '01', $numeroFiscal, $c['serie'], $usuarioId);

        $this->evento([
            'venta_id' => $ventaId, 'operacion' => 'EMITIR', 'tipo_documento' => '01', 'numero_documento' => $numeroFiscal,
            'estado' => 'OK', 'mensaje' => $resp['mensaje'] ?? 'Documento procesado correctamente.',
            'payload' => $payload, 'respuesta' => $resp, 'usuario_id' => $usuarioId,
        ]);

        return $this->resultadoVenta($ventaId) + ['pdf' => $pdfOk];
    }

    /** Intenta recuperar una factura que TFHKA ya pudo haber procesado. */
    private function recuperarVenta(int $ventaId, string $numero, string $fecha, ?int $usuarioId): ?array
    {
        try {
            $estado = $this->cliente()->estado('01', $numero, $this->config()['serie']);
        } catch (Throwable) {
            return null;
        }

        if (($estado['codigo'] ?? '') !== '200') {
            return null;
        }
        $e = $estado['estado'] ?? [];
        $control = trim((string) ($e['numeroControl'] ?? ''));
        if ($control === '') {
            return null;
        }

        $this->marcarVenta($ventaId, (string) ($e['numeroDocumento'] ?? $numero), $control, 'EMITIDA', $estado, true, true);
        $this->guardarPdfVenta($ventaId, '01', (string) ($e['numeroDocumento'] ?? $numero), $this->config()['serie'], $usuarioId);

        $this->evento([
            'venta_id' => $ventaId, 'operacion' => 'ESTADO', 'tipo_documento' => '01', 'numero_documento' => $numero,
            'estado' => 'OK', 'mensaje' => 'Documento recuperado de TFHKA sin reemitir.', 'respuesta' => $estado, 'usuario_id' => $usuarioId,
        ]);

        return $this->resultadoVenta($ventaId);
    }

    private function marcarVenta(int $ventaId, ?string $numeroFiscal, ?string $control, string $estado, ?array $respuesta, bool $setNumero, bool $setFecha): void
    {
        $sets = ['tfhka_estado = ?', 'tfhka_respuesta = ?::jsonb'];
        $args = [$estado, $respuesta !== null ? json_encode($respuesta, JSON_UNESCAPED_UNICODE) : null];

        if ($numeroFiscal !== null && $setNumero) {
            $sets[] = 'numero_factura_fiscal = COALESCE(numero_factura_fiscal, ?)';
            $args[] = $numeroFiscal;
        }
        if ($control !== null) {
            $sets[] = 'numero_control = COALESCE(numero_control, ?)';
            $args[] = $control;
        }
        if (isset($respuesta['resultado']['urlConsulta'])) {
            $sets[] = 'url_consulta = ?';
            $args[] = (string) $respuesta['resultado']['urlConsulta'];
        } elseif (isset($respuesta['estado']['urlConsulta'])) {
            $sets[] = 'url_consulta = ?';
            $args[] = (string) $respuesta['estado']['urlConsulta'];
        }
        if ($setFecha && $estado === 'EMITIDA') {
            $sets[] = 'tfhka_emitida_en = now()';
        }
        if ($estado === 'ANULADA') {
            $sets[] = 'tfhka_anulada_en = now()';
        }

        $args[] = $ventaId;
        DB::update('UPDATE ventas SET '.implode(', ', $sets).' WHERE id = ?', $args);
    }

    private function guardarPdfVenta(int $ventaId, string $tipo, string $numero, string $serie, ?int $usuarioId): bool
    {
        if (! ($this->config()['guardar_pdf'] ?? true)) {
            return false;
        }
        try {
            $bytes = $this->cliente()->pdf($tipo, $numero, $serie);
            DB::update("UPDATE ventas SET pdf_fiscal = decode(?, 'base64') WHERE id = ?", [base64_encode($bytes), $ventaId]);
            $this->evento([
                'venta_id' => $ventaId, 'operacion' => 'PDF', 'tipo_documento' => $tipo, 'numero_documento' => $numero,
                'estado' => 'OK', 'mensaje' => 'PDF oficial guardado ('.strlen($bytes).' bytes).', 'usuario_id' => $usuarioId,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->evento([
                'venta_id' => $ventaId, 'operacion' => 'PDF', 'tipo_documento' => $tipo, 'numero_documento' => $numero,
                'estado' => 'ERROR', 'mensaje' => $e->getMessage(), 'usuario_id' => $usuarioId,
            ]);

            return false;
        }
    }

    public function resultadoVenta(int $ventaId): array
    {
        $d = DB::selectOne('SELECT numero, numero_factura_fiscal, numero_control, url_consulta, tfhka_estado, pdf_fiscal IS NOT NULL AS tiene_pdf, tfhka_emitida_en FROM ventas WHERE id = ?', [$ventaId]);
        if (! $d) {
            return [];
        }

        return [
            'venta_id' => $ventaId,
            'numero' => (int) $d->numero,
            'numero_documento' => $d->numero_factura_fiscal,
            'numero_control' => $d->numero_control,
            'url_consulta' => $d->url_consulta,
            'estado' => $d->tfhka_estado,
            'tiene_pdf' => (bool) $d->tiene_pdf,
            'emitida_en' => $d->tfhka_emitida_en,
        ];
    }

    /* ───────────── Notas de crédito / débito ───────────── */

    public function emitirNota(int $notaId, ?int $usuarioId = null, bool $forzar = false): array
    {
        if (! $this->activo()) {
            throw new TfhkaException('La facturación digital no está activa para esta tienda.');
        }

        $docs = new DatosDocumento();
        $d = $docs->nota($notaId);
        if (! $d) {
            throw new TfhkaException('La nota no existe.');
        }
        if (($d['venta']['estado'] ?? '') !== 'COMPLETADA') {
            throw new TfhkaException('La venta de esta nota está anulada: emite una nota nueva desde la venta original.');
        }
        if (! $forzar && $d['tfhka_estado'] === 'EMITIDA' && ! empty($d['numero_control'])) {
            return $this->resultadoNota($notaId);
        }

        // Una nota fiscal exige la factura fiscal de la venta afectada.
        $factura = trim((string) ($d['venta_factura'] ?? ''));
        if ($factura === '') {
            $this->emitirVenta((int) $d['venta']['id'], $usuarioId);
            $d = $docs->nota($notaId);
            $factura = trim((string) ($d['venta_factura'] ?? ''));
            if ($factura === '') {
                throw new TfhkaException('No se pudo emitir la factura de la venta afectada; la nota quedó pendiente.');
            }
        }

        $numeroFiscal = trim((string) ($d['numero_fiscal'] ?? ''));
        if ($numeroFiscal === '') {
            $numeroFiscal = (string) $this->reservarNumeroNota();
            DB::update('UPDATE notas_ajuste SET numero_fiscal = ? WHERE id = ? AND numero_fiscal IS NULL', [$numeroFiscal, $notaId]);
            $d['numero_fiscal'] = $numeroFiscal;
        }

        $tipo = $d['tipo'] === 'D' ? '03' : '02';

        // Modo «máquina fiscal»: la nota se imprime en la máquina con la misma numeración fiscal.
        if ($this->maquina()->modo() === 'MAQUINA_FISCAL') {
            $numeroFiscal = trim((string) ($d['numero_fiscal'] ?? ''));
            if ($numeroFiscal === '') {
                $numeroFiscal = (string) $this->reservarNumeroNota();
                DB::update('UPDATE notas_ajuste SET numero_fiscal = ? WHERE id = ? AND numero_fiscal IS NULL', [$numeroFiscal, $notaId]);
            }
            $imp = $this->maquina()->imprimirNota($d, $d['tipo']);
            DB::update("UPDATE notas_ajuste SET numero_fiscal = ?, numero_control = ?, tfhka_estado = 'EMITIDA', tfhka_emitida_en = now(), fuente_fiscal = 'MAQUINA_FISCAL' WHERE id = ?", [$imp['factura'], $imp['control'], $notaId]);
            $this->logBitacora([
                'nota_id' => $notaId, 'venta_id' => (int) ($d['venta']['id'] ?? 0), 'operacion' => 'EMITIR', 'tipo_documento' => $tipo,
                'numero_documento' => $numeroFiscal, 'estado' => 'OK', 'mensaje' => 'Nota impresa en máquina fiscal (control '.$imp['control'].').',
                'respuesta' => $imp, 'usuario_id' => $usuarioId,
            ]);

            return $this->resultadoNota($notaId);
        }

        $c = $this->config();

        if (! $forzar) {
            $recuperado = $this->recuperarNota($notaId, $tipo, $numeroFiscal, $usuarioId);
            if ($recuperado) {
                return $recuperado;
            }
        }

        $payload = $this->builder->nota($d, $c);
        $this->evento([
            'nota_id' => $notaId, 'venta_id' => (int) $d['venta']['id'], 'operacion' => 'EMITIR', 'tipo_documento' => $tipo,
            'numero_documento' => $numeroFiscal, 'estado' => 'OK', 'mensaje' => 'Enviando nota a TFHKA.',
            'payload' => $payload, 'usuario_id' => $usuarioId,
        ]);

        try {
            $resp = $this->cliente()->emitir($payload);
        } catch (Throwable $e) {
            $this->marcarNota($notaId, null, 'ERROR', ['error' => $e->getMessage()]);
            $this->evento([
                'nota_id' => $notaId, 'venta_id' => (int) $d['venta']['id'], 'operacion' => 'EMITIR', 'tipo_documento' => $tipo,
                'numero_documento' => $numeroFiscal, 'estado' => 'ERROR', 'mensaje' => $e->getMessage(),
                'payload' => $payload, 'usuario_id' => $usuarioId,
            ]);
            throw $e;
        }

        $res = $resp['resultado'] ?? $resp;
        $control = trim((string) ($res['numeroControl'] ?? ''));
        if ($control === '') {
            $msg = $resp['mensaje'] ?? 'TFHKA no devolvió número de control.';
            $this->marcarNota($notaId, null, 'ERROR', $resp);
            $this->evento([
                'nota_id' => $notaId, 'venta_id' => (int) $d['venta']['id'], 'operacion' => 'EMITIR', 'tipo_documento' => $tipo,
                'numero_documento' => $numeroFiscal, 'estado' => 'ERROR', 'mensaje' => $msg,
                'payload' => $payload, 'respuesta' => $resp, 'usuario_id' => $usuarioId,
            ]);
            throw new TfhkaException($msg);
        }

        $this->marcarNota($notaId, $control, 'EMITIDA', $resp, (string) ($res['urlConsulta'] ?? ''));
        $this->guardarPdfNota($notaId, $tipo, $numeroFiscal, $c['serie'], $usuarioId);

        $this->evento([
            'nota_id' => $notaId, 'venta_id' => (int) $d['venta']['id'], 'operacion' => 'EMITIR', 'tipo_documento' => $tipo,
            'numero_documento' => $numeroFiscal, 'estado' => 'OK', 'mensaje' => $resp['mensaje'] ?? 'Documento procesado correctamente.',
            'payload' => $payload, 'respuesta' => $resp, 'usuario_id' => $usuarioId,
        ]);

        return $this->resultadoNota($notaId);
    }

    private function recuperarNota(int $notaId, string $tipo, string $numero, ?int $usuarioId): ?array
    {
        try {
            $estado = $this->cliente()->estado($tipo, $numero, $this->config()['serie']);
        } catch (Throwable) {
            return null;
        }
        if (($estado['codigo'] ?? '') !== '200') {
            return null;
        }
        $e = $estado['estado'] ?? [];
        $control = trim((string) ($e['numeroControl'] ?? ''));
        if ($control === '') {
            return null;
        }

        $this->marcarNota($notaId, $control, 'EMITIDA', $estado, (string) ($e['urlConsulta'] ?? ''));
        $this->guardarPdfNota($notaId, $tipo, $numero, $this->config()['serie'], $usuarioId);
        $this->evento([
            'nota_id' => $notaId, 'operacion' => 'ESTADO', 'tipo_documento' => $tipo, 'numero_documento' => $numero,
            'estado' => 'OK', 'mensaje' => 'Nota recuperada de TFHKA sin reemitir.', 'respuesta' => $estado, 'usuario_id' => $usuarioId,
        ]);

        return $this->resultadoNota($notaId);
    }

    private function marcarNota(int $notaId, ?string $control, string $estado, ?array $respuesta, ?string $url = null): void
    {
        $sets = ['tfhka_estado = ?', 'tfhka_respuesta = ?::jsonb'];
        $args = [$estado, $respuesta !== null ? json_encode($respuesta, JSON_UNESCAPED_UNICODE) : null];

        if ($control !== null) {
            $sets[] = 'numero_control = COALESCE(numero_control, ?)';
            $args[] = $control;
        }
        if ($url !== null && $url !== '') {
            $sets[] = 'url_consulta = ?';
            $args[] = $url;
        } elseif (isset($respuesta['resultado']['urlConsulta'])) {
            $sets[] = 'url_consulta = ?';
            $args[] = (string) $respuesta['resultado']['urlConsulta'];
        } elseif (isset($respuesta['estado']['urlConsulta'])) {
            $sets[] = 'url_consulta = ?';
            $args[] = (string) $respuesta['estado']['urlConsulta'];
        }
        if ($estado === 'EMITIDA') {
            $sets[] = 'tfhka_emitida_en = COALESCE(tfhka_emitida_en, now())';
        }
        if ($estado === 'ANULADA') {
            $sets[] = 'tfhka_anulada_en = now()';
        }

        $args[] = $notaId;
        DB::update('UPDATE notas_ajuste SET '.implode(', ', $sets).' WHERE id = ?', $args);
    }

    private function guardarPdfNota(int $notaId, string $tipo, string $numero, string $serie, ?int $usuarioId): bool
    {
        if (! ($this->config()['guardar_pdf'] ?? true)) {
            return false;
        }
        try {
            $bytes = $this->cliente()->pdf($tipo, $numero, $serie);
            DB::update("UPDATE notas_ajuste SET pdf_fiscal = decode(?, 'base64') WHERE id = ?", [base64_encode($bytes), $notaId]);
            $this->evento([
                'nota_id' => $notaId, 'operacion' => 'PDF', 'tipo_documento' => $tipo, 'numero_documento' => $numero,
                'estado' => 'OK', 'mensaje' => 'PDF oficial guardado ('.strlen($bytes).' bytes).', 'usuario_id' => $usuarioId,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->evento([
                'nota_id' => $notaId, 'operacion' => 'PDF', 'tipo_documento' => $tipo, 'numero_documento' => $numero,
                'estado' => 'ERROR', 'mensaje' => $e->getMessage(), 'usuario_id' => $usuarioId,
            ]);

            return false;
        }
    }

    public function resultadoNota(int $notaId): array
    {
        $d = DB::selectOne('SELECT id, tipo, numero, numero_fiscal, numero_control, url_consulta, tfhka_estado, pdf_fiscal IS NOT NULL AS tiene_pdf, tfhka_emitida_en FROM notas_ajuste WHERE id = ?', [$notaId]);
        if (! $d) {
            return [];
        }

        return [
            'nota_id' => (int) $d->id,
            'tipo' => $d->tipo,
            'numero' => $d->numero,
            'numero_documento' => $d->numero_fiscal,
            'numero_control' => $d->numero_control,
            'url_consulta' => $d->url_consulta,
            'estado' => $d->tfhka_estado,
            'tiene_pdf' => (bool) $d->tiene_pdf,
            'emitida_en' => $d->tfhka_emitida_en,
        ];
    }

    /** Anula una nota ya emitida (las facturas NO se anulan: se revierten con nota de crédito). */
    public function anularNota(int $notaId, string $motivo, ?int $usuarioId = null): array
    {
        if (! $this->activo()) {
            throw new TfhkaException('La facturación digital no está activa para esta tienda.');
        }
        $d = (new DatosDocumento())->nota($notaId);
        if (! $d) {
            throw new TfhkaException('La nota no existe.');
        }
        $numero = trim((string) ($d['numero_fiscal'] ?? ''));
        if ($numero === '') {
            throw new TfhkaException('La nota no tiene número fiscal: no se emitió en TFHKA.');
        }

        // En modo máquina fiscal una nota ya impresa no se anula en el equipo:
        // la reversión fiscal es una nota contraria (NC ↔ ND) desde la venta original.
        if ($this->maquina()->modo() === 'MAQUINA_FISCAL') {
            throw new TfhkaException('En modo máquina fiscal una nota impresa no se anula: registra la nota contraria (NC ↔ ND) desde la venta original.');
        }
        $tipo = $d['tipo'] === 'D' ? '03' : '02';

        $resp = $this->cliente()->anular([
            'serie' => $this->config()['serie'],
            'tipoDocumento' => $tipo,
            'numeroDocumento' => $numero,
            'motivoAnulacion' => mb_substr(trim($motivo), 0, 300),
            'fechaAnulacion' => date('d/m/Y'),
            'horaAnulacion' => date('h:i:s a'),
        ]);

        $this->marcarNota($notaId, null, 'ANULADA', $resp);
        $this->evento([
            'nota_id' => $notaId, 'operacion' => 'ANULAR', 'tipo_documento' => $tipo, 'numero_documento' => $numero,
            'estado' => 'OK', 'mensaje' => $resp['mensaje'] ?? 'Documento anulado exitosamente.', 'respuesta' => $resp, 'usuario_id' => $usuarioId,
        ]);

        return $this->resultadoNota($notaId);
    }

    /**
     * Anula una venta cumpliendo la regla fiscal: si tiene factura emitida, primero
     * se emite una nota de crédito por lo que falte por acreditar y luego se marca
     * la venta como ANULADA. Si la nota falla, la venta NO se anula.
     */
    public function anularVenta(int $ventaId, string $motivo, ?int $usuarioId = null): array
    {
        $datos = (new DatosDocumento())->venta($ventaId);
        if (! $datos) {
            throw new TfhkaException('La venta no existe.');
        }
        if ($datos['estado'] === 'ANULADA') {
            throw new TfhkaException('La venta ya está anulada.');
        }

        if ($this->activo() && $datos['tfhka_estado'] === 'EMITIDA' && ! empty($datos['factura'])) {
            $acreditado = (float) DB::scalar("SELECT COALESCE(sum(CASE WHEN tipo = 'C' THEN base_ves ELSE -base_ves END), 0) FROM notas_ajuste WHERE venta_id = ?", [$ventaId]);
            $baseVenta = (float) $datos['totales']['base_ves'] + (float) $datos['totales']['exento_ves'];
            $restante = round($baseVenta - $acreditado, 2);

            if ($restante > 0.009) {
                $numero = $this->siguienteNota('C');
                (new \App\Http\Controllers\VentasController())->registrarNota('C', $ventaId, $numero, $restante, $motivo, null, null, false, null);
                $notaId = (int) DB::scalar('SELECT id FROM notas_ajuste WHERE tipo = ? AND numero = ?', ['C', $numero]);
                if ($notaId > 0) {
                    $this->emitirNota($notaId, $usuarioId);
                }
            }
        }

        DB::statement('CALL sp_anular_venta(?, ?, ?)', [$ventaId, $usuarioId ?? auth()->id(), $motivo]);

        return $this->resultadoVenta($ventaId);
    }

    private function siguienteNota(string $tipo): string
    {
        $pre = $tipo === 'C' ? 'NC-' : 'ND-';
        $n = (int) DB::scalar("SELECT COALESCE(max(substring(numero FROM '^".$pre."(\\d+)$')::bigint), 0) + 1 FROM notas_ajuste WHERE tipo = ?", [$tipo]);

        return $pre.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    private function reservarNumeroNota(): int
    {
        return (int) DB::transaction(function () {
            DB::statement('INSERT INTO tfhka_config (negocio_id) VALUES (fn_negocio_actual()) ON CONFLICT (negocio_id) DO NOTHING');
            $row = DB::selectOne('SELECT siguiente_nota_fiscal FROM tfhka_config WHERE negocio_id = fn_negocio_actual() FOR UPDATE');
            if (! $row) {
                throw new TfhkaException('Falta la fila de configuración de facturación digital.');
            }
            $max = (int) DB::scalar("SELECT COALESCE(max(numero_fiscal::bigint), 0) FROM notas_ajuste WHERE numero_fiscal ~ '^[0-9]+$'");
            $n = max((int) $row->siguiente_nota_fiscal, $max + 1);
            DB::update('UPDATE tfhka_config SET siguiente_nota_fiscal = ?, updated_at = now() WHERE negocio_id = fn_negocio_actual()', [$n + 1]);

            return $n;
        });
    }

    /* ───────────── Bitácora ───────────── */

    private function evento(array $d): void
    {
        if (! $this->tablaLista()) {
            return;
        }
        try {
            $negocioId = $this->config()['negocio_id'];
            DB::insert(
                'INSERT INTO tfhka_eventos (negocio_id, venta_id, nota_id, operacion, tipo_documento, numero_documento, estado, mensaje, payload, respuesta, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)',
                [
                    $negocioId, $d['venta_id'] ?? null, $d['nota_id'] ?? null, $d['operacion'] ?? 'ESTADO',
                    $d['tipo_documento'] ?? null, $d['numero_documento'] ?? null, $d['estado'] ?? 'OK',
                    $d['mensaje'] ?? null,
                    isset($d['payload']) ? json_encode($d['payload'], JSON_UNESCAPED_UNICODE) : null,
                    isset($d['respuesta']) ? json_encode($d['respuesta'], JSON_UNESCAPED_UNICODE) : null,
                    $d['usuario_id'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('TFHKA: no se pudo escribir tfhka_eventos: '.$e->getMessage());
        }
    }
}