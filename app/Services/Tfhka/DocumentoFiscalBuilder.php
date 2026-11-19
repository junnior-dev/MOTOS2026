<?php

namespace App\Services\Tfhka;

/**
 * Traduce una venta o una nota del sistema al JSON que exige la API v2 de
 * The Factory HKA (documentoElectronico).
 *
 * Decisiones fiscales (validadas contra el ambiente demo):
 *   · La factura usa como NumeroDocumento el número interno de la venta (solo dígitos).
 *   · Moneda del documento: BSD (bolívar) por defecto; las formas de pago conservan
 *     su moneda original y su TipoCambio.
 *   · CodigoImpuesto por línea: G = IVA general, E = exento, F = exonerado.
 *     (El código X que aparece en el esquema es una alícuota 0 % que TFHKA cuenta
 *      como GRAVADO; por eso no se usa para exonerado.)
 *   · El IGTF va dentro de Totales.ImpuestosSubtotal como CodigoTotalImp "IGTF".
 *   · TotalAPagar = base + exento + IVA + IGTF.
 */
class DocumentoFiscalBuilder
{
    /** Factura (TipoDocumento 01). */
    public function factura(array $d, array $cfg): array
    {
        $moneda = $this->moneda($cfg);
        $lineas = $this->lineas($d, $cfg);
        $totales = $this->totales($d, $cfg, $lineas, false);

        return [
            'Encabezado' => [
                'IdentificacionDocumento' => $this->identificacion('01', (string) $d['numero'], $d, $cfg, $moneda),
                'Vendedor' => null,
                'Comprador' => $this->comprador($d['cliente'] ?? null, $d),
                'Totales' => $totales,
                'TotalesRetencion' => null,
                'TotalesOtraMoneda' => null,
                'Orden' => null,
                'InfoAdicional' => $this->infoAdicional($d, $cfg, false),
            ],
            'DetallesItems' => $lineas,
        ];
    }

    /** Nota de crédito (02) o débito (03). */
    public function nota(array $d, array $cfg): array
    {
        $moneda = $this->moneda($cfg);
        $venta = $d['venta'] ?? [];
        $tipo = $d['tipo'] === 'D' ? '03' : '02';
        $lineas = [$this->lineaNota($d, $cfg)];
        $totales = $this->totalesNota($d, $cfg, $lineas);

        $ident = $this->identificacion($tipo, (string) $d['numero_fiscal'], $d, $cfg, $moneda);
        $ident['SerieFacturaAfectada'] = (string) ($cfg['serie'] ?? '');
        $ident['NumeroFacturaAfectada'] = (string) ($venta['factura'] ?? '');
        $ident['FechaFacturaAfectada'] = (string) ($venta['fecha'] ?? '');
        $ident['MontoFacturaAfectada'] = $this->q2($venta['totales']['total_cobrar_ves'] ?? $venta['totales']['total_ves'] ?? 0);
        $ident['ComentarioFacturaAfectada'] = $this->limpio($d['motivo'] ?? '', 255);
        $ident['TipoDePago'] = ! empty($venta['credito']['es']) ? 'Crédito' : 'Inmediato';

        return [
            'Encabezado' => [
                'IdentificacionDocumento' => $ident,
                'Vendedor' => null,
                'Comprador' => $this->comprador($venta['cliente'] ?? $d['cliente'] ?? null, $venta),
                'Totales' => $totales,
                'TotalesRetencion' => null,
                'TotalesOtraMoneda' => null,
                'Orden' => null,
                'InfoAdicional' => $this->infoAdicional($d, $cfg, true),
            ],
            'DetallesItems' => $lineas,
        ];
    }

    /* ───────────── Identificación y comprador ───────────── */

    private function identificacion(string $tipo, string $numero, array $d, array $cfg, string $moneda): array
    {
        $esCredito = ! empty($d['credito']['es']);
        $vence = $esCredito ? ($d['credito']['vence'] ?? null) : null;

        return [
            'TipoDocumento' => $tipo,
            'NumeroDocumento' => $this->soloDigitos($numero),
            'TipoProveedor' => null,
            'TipoTransaccion' => null,
            'NumeroPlanillaImportacion' => null,
            'NumeroExpedienteImportacion' => null,
            'SerieFacturaAfectada' => null,
            'NumeroFacturaAfectada' => null,
            'FechaFacturaAfectada' => null,
            'MontoFacturaAfectada' => null,
            'ComentarioFacturaAfectada' => null,
            'RegimenEspTributacion' => $this->texto($cfg['regimen_esp_tributacion'] ?? null, 55),
            'FechaEmision' => (string) $d['fecha'],
            'FechaVencimiento' => $vence ?: (string) $d['fecha'],
            'HoraEmision' => $this->horaFiscal($d),
            'Anulado' => false,
            'TipoDePago' => $esCredito ? 'Crédito' : 'Inmediato',
            'Serie' => (string) ($cfg['serie'] ?? ''),
            'Sucursal' => (string) ($cfg['sucursal'] ?? ''),
            'TipoDeVenta' => 'Interna',
            'Moneda' => $moneda,
        ];
    }

    private function comprador(?array $cliente, array $d): array
    {
        $tipo = strtoupper(trim((string) ($cliente['tipo'] ?? 'V')));
        if (! preg_match('/^([VGCJEP]|[A-Z0-9]{2,4})$/', $tipo)) {
            $tipo = 'V';
        }
        $documento = preg_replace('/[^A-Za-z0-9]/', '', (string) ($cliente['documento'] ?? '')) ?: '00000000';
        $nombre = $this->texto($cliente['nombre'] ?? 'CONSUMIDOR FINAL', 255) ?: 'CONSUMIDOR FINAL';
        $direccion = $this->texto($cliente['direccion'] ?? '', 255) ?: 'VENEZUELA';
        $telefono = trim((string) ($cliente['telefono'] ?? ''));
        $correo = trim((string) ($cliente['correo'] ?? ''));

        $correos = [];
        if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $correos[] = $correo;
        }

        return [
            'TipoIdentificacion' => $tipo,
            'NumeroIdentificacion' => $this->texto($documento, 45),
            'RazonSocial' => $nombre,
            'Direccion' => $direccion,
            'Ubigeo' => null,
            'Pais' => 'VE',
            'Notificar' => $correos ? 'Si' : null,
            'Telefono' => $telefono !== '' ? [$this->texto($telefono, 40)] : [],
            'Correo' => $correos,
            'OtrosEnvios' => null,
        ];
    }

    /* ───────────── Ítems ───────────── */

    private function lineas(array $d, array $cfg): array
    {
        $out = [];
        $n = 0;
        foreach (($d['lineas'] ?? []) as $l) {
            $n++;
            $cantidad = (float) ($l['cantidad'] ?? 0);
            $base = (float) ($l['base_ves'] ?? 0);
            $iva = (float) ($l['iva_ves'] ?? 0);
            $dcto = (float) ($l['descuento_pct'] ?? 0);
            $factor = $cantidad * (1 - $dcto / 100);
            $precio = $factor > 0 ? $base / $factor : ($cantidad > 0 ? $base / $cantidad : $base);
            $antes = $precio * $cantidad;
            $codigo = in_array($l['alicuota'] ?? '', ['G', 'E', 'F'], true) ? $l['alicuota'] : 'G';

            $out[] = [
                'NumeroLinea' => (string) $n,
                'CodigoCIIU' => $this->texto($cfg['codigo_ciiu'] ?? '4663', 6),
                'CodigoPLU' => $this->texto($l['sku'] ?? ($cfg['codigo_plu_default'] ?? ''), 20),
                'IndicadorBienoServicio' => '1',
                'Descripcion' => $this->texto($l['descripcion'] ?? 'PRODUCTO', 1000),
                'Cantidad' => $this->cantidad($cantidad),
                'UnidadMedida' => $this->unidad($l['unidad'] ?? 'UND'),
                'PrecioUnitario' => $this->q4($precio),
                'PrecioUnitarioDescuento' => null,
                'MontoBonificacion' => null,
                'DescripcionBonificacion' => null,
                'DescuentoMonto' => $this->q2(max(0, $antes - $base)),
                'RecargoMonto' => '0',
                'PrecioItem' => $this->q2($base),
                'PrecioAntesDescuento' => $this->q2($antes),
                'CodigoImpuesto' => $codigo,
                'TasaIVA' => $codigo === 'G' ? $this->tasa($l['iva_pct'] ?? $d['regimen']['iva_pct'] ?? 0) : '0',
                'ValorIVA' => $this->q2($iva),
                'ValorTotalItem' => $this->q2($base + $iva),
                'InfoAdicionalItem' => [],
                'ListaItemOTI' => null,
            ];
        }

        return $out;
    }

    private function lineaNota(array $d, array $cfg): array
    {
        $codigo = $this->impuestoNota($d);
        $base = (float) ($d['base_ves'] ?? 0);
        $iva = (float) ($d['iva_ves'] ?? 0);
        $tipo = $d['tipo'] === 'D' ? 'DÉBITO' : 'CRÉDITO';
        $ventaNum = $d['venta']['numero'] ?? '';

        return [
            'NumeroLinea' => '1',
            'CodigoCIIU' => $this->texto($cfg['codigo_ciiu'] ?? '4663', 6),
            'CodigoPLU' => $this->texto($d['tipo'].$ventaNum, 20),
            'IndicadorBienoServicio' => '1',
            'Descripcion' => $this->texto('NOTA DE '.$tipo.': '.($d['motivo'] ?? ''), 1000),
            'Cantidad' => '1',
            'UnidadMedida' => 'UNI',
            'PrecioUnitario' => $this->q4($base),
            'PrecioUnitarioDescuento' => null,
            'MontoBonificacion' => null,
            'DescripcionBonificacion' => null,
            'DescuentoMonto' => '0.00',
            'RecargoMonto' => '0',
            'PrecioItem' => $this->q2($base),
            'PrecioAntesDescuento' => $this->q2($base),
            'CodigoImpuesto' => $codigo,
            'TasaIVA' => $codigo === 'G' ? $this->tasa($d['iva_pct'] ?? 0) : '0',
            'ValorIVA' => $this->q2($iva),
            'ValorTotalItem' => $this->q2($base + $iva),
            'InfoAdicionalItem' => [],
            'ListaItemOTI' => null,
        ];
    }

    /* ───────────── Totales ───────────── */

    private function totales(array $d, array $cfg, array $lineas, bool $esNota): array
    {
        $moneda = $this->moneda($cfg);
        $t = $d['totales'];
        $base = (float) $t['base_ves'];
        $exentoTotal = (float) $t['exento_ves'];
        $iva = (float) $t['iva_ves'];
        $total = (float) $t['total_ves'];
        $igtf = (float) ($t['igtf_ves'] ?? 0);
        $totalPagar = (float) ($t['total_cobrar_ves'] ?? $total + $igtf);

        // Separar exento (E) y exonerado (F) con las líneas; la cabecera los suma junta.
        $e = 0.0;
        $f = 0.0;
        foreach ($lineas as $l) {
            if (($l['CodigoImpuesto'] ?? '') === 'E') {
                $e += (float) $l['PrecioItem'];
            } elseif (($l['CodigoImpuesto'] ?? '') === 'F') {
                $f += (float) $l['PrecioItem'];
            }
        }
        $sumaEF = $e + $f;
        if (abs($sumaEF - $exentoTotal) > 0.009) {
            if ($f > $e) {
                $f += $exentoTotal - $sumaEF;
            } else {
                $e += $exentoTotal - $sumaEF;
            }
        }
        if ($e < 0) { $f += $e; $e = 0; }
        if ($f < 0) { $e += $f; $f = 0; }

        // Descuento global antes de impuestos (informativo, como en el fixture).
        $antes = 0.0;
        foreach (($d['lineas'] ?? []) as $l) {
            $antes += (float) $l['base_ves'] / max(0.0001, 1 - ((float) $l['descuento_pct'] / 100));
        }
        $descuento = max(0, round($antes - ($base + $exentoTotal), 2));

        $impuestos = [];
        if ($base > 0) {
            $impuestos[] = [
                'CodigoTotalImp' => 'G',
                'AlicuotaImp' => $this->q2($d['regimen']['iva_pct'] ?? 0),
                'BaseImponibleImp' => $this->q2($base),
                'ValorTotalImp' => $this->q2($iva),
            ];
        }
        if ($e > 0) {
            $impuestos[] = ['CodigoTotalImp' => 'E', 'AlicuotaImp' => '0.00', 'BaseImponibleImp' => $this->q2($e), 'ValorTotalImp' => '0.00'];
        }
        if ($f > 0) {
            $impuestos[] = ['CodigoTotalImp' => 'F', 'AlicuotaImp' => '0.00', 'BaseImponibleImp' => $this->q2($f), 'ValorTotalImp' => '0.00'];
        }
        if ($igtf > 0) {
            $baseIgtf = ($d['regimen']['igtf_base'] ?? 'CON_IVA') === 'SIN_IVA' ? max(0, $total - $iva) : $total;
            $impuestos[] = [
                'CodigoTotalImp' => 'IGTF',
                'AlicuotaImp' => $this->q2($d['regimen']['igtf_pct'] ?? 3),
                'BaseImponibleImp' => $this->q2($baseIgtf),
                'ValorTotalImp' => $this->q2($igtf),
            ];
        }

        return [
            'NroItems' => (string) count($lineas),
            'MontoGravadoTotal' => $this->q2($base),
            'MontoExentoTotal' => $this->q2($exentoTotal),
            'MontoPercibidoTotal' => '0.00',
            'SubtotalAntesDescuento' => $this->q2($base + $exentoTotal + $descuento),
            'TotalDescuento' => $descuento > 0 ? $this->q2($descuento) : null,
            'TotalRecargos' => null,
            'Subtotal' => $this->q2($base + $exentoTotal),
            'TotalIVA' => $this->q2($iva),
            'MontoTotalConIVA' => $this->q2($total),
            'TotalAPagar' => $this->q2($totalPagar),
            'MontoEnLetras' => $this->letras($totalPagar, $moneda),
            'ListaRecargo' => null,
            'ListaDescBonificacion' => null,
            'ImpuestosSubtotal' => $impuestos,
            'OtrosImpuestosSubtotal' => null,
            'FormasPago' => $this->formasPago($d, $cfg, $totalPagar),
            'TotalIGTF' => null,
            'TotalIGTF_VES' => null,
            'MontoTotalOTI' => null,
            'MontoTotalIVAyOTI' => null,
        ];
    }

    private function totalesNota(array $d, array $cfg, array $lineas): array
    {
        $moneda = $this->moneda($cfg);
        $base = (float) ($d['base_ves'] ?? 0);
        $iva = (float) ($d['iva_ves'] ?? 0);
        $total = (float) ($d['total_ves'] ?? ($base + $iva));
        $codigo = $lineas[0]['CodigoImpuesto'] ?? 'G';

        $impuestos = [[
            'CodigoTotalImp' => $codigo,
            'AlicuotaImp' => $codigo === 'G' ? $this->q2($d['iva_pct'] ?? 0) : '0.00',
            'BaseImponibleImp' => $this->q2($base),
            'ValorTotalImp' => $this->q2($iva),
        ]];

        $venta = $d['venta'] ?? [];
        $forma = $this->formaPago($venta['pagos'][0]['tfhka_forma'] ?? null, $venta['pagos'][0]['metodo'] ?? 'Efectivo', $moneda);
        $descripcion = $d['tipo'] === 'D' ? 'Cobro de ajuste' : 'Devolución';

        return [
            'NroItems' => '1',
            'MontoGravadoTotal' => $codigo === 'G' ? $this->q2($base) : '0.00',
            'MontoExentoTotal' => $codigo === 'G' ? '0.00' : $this->q2($base),
            'MontoPercibidoTotal' => '0.00',
            'SubtotalAntesDescuento' => $this->q2($base),
            'TotalDescuento' => null,
            'TotalRecargos' => null,
            'Subtotal' => $this->q2($base),
            'TotalIVA' => $this->q2($iva),
            'MontoTotalConIVA' => $this->q2($total),
            'TotalAPagar' => $this->q2($total),
            'MontoEnLetras' => $this->letras($total, $moneda),
            'ListaRecargo' => null,
            'ListaDescBonificacion' => null,
            'ImpuestosSubtotal' => $impuestos,
            'OtrosImpuestosSubtotal' => null,
            'FormasPago' => [[
                'Descripcion' => $descripcion,
                'Fecha' => (string) $d['fecha'],
                'Forma' => $forma,
                'Monto' => $this->q2($total),
                'Moneda' => $moneda,
                'TipoCambio' => '0.0000',
            ]],
            'TotalIGTF' => null,
            'TotalIGTF_VES' => null,
            'MontoTotalOTI' => null,
            'MontoTotalIVAyOTI' => null,
        ];
    }

    /* ───────────── Formas de pago ───────────── */

    private function formasPago(array $d, array $cfg, float $totalPagar): array
    {
        $moneda = $this->moneda($cfg);
        $tasaUsd = (float) ($d['tasas']['USD'] ?? 0);
        $pagos = $d['pagos'] ?? [];
        $out = [];
        $sumaDoc = 0.0;

        foreach ($pagos as $p) {
            $mon = strtoupper(trim((string) ($p['moneda'] ?? 'VES')));
            $equiv = (float) ($p['ves'] ?? 0) + (float) ($p['igtf_ves'] ?? 0);
            $sumaDoc += $equiv;

            if ($mon === 'VES' && in_array($moneda, ['BSD', 'VES'], true)) {
                $m = in_array($moneda, ['BSD', 'VES'], true) ? $moneda : 'BSD';
                $tc = '0.0000';
                $monto = (float) ($p['cobrado'] ?? $p['monto'] ?? 0);
            } elseif ($mon === 'USD') {
                $m = 'USD';
                $tc = $this->q4($tasaUsd > 0 ? $tasaUsd : ($p['tasa'] ?? 0));
                $monto = (float) ($p['cobrado'] ?? $p['monto'] ?? 0);
            } else {
                $m = $mon ?: 'USD';
                $tc = $this->q4((float) ($p['tasa'] ?? 0));
                $monto = (float) ($p['cobrado'] ?? $p['monto'] ?? 0);
            }

            $out[] = [
                'Descripcion' => $this->texto($p['metodo'] ?? 'Pago', 100),
                'Fecha' => (string) $d['fecha'],
                'Forma' => $this->formaPago($p['tfhka_forma'] ?? null, $p['metodo'] ?? '', $mon),
                'Monto' => $this->q2($monto),
                'Moneda' => $m,
                'TipoCambio' => $tc,
            ];
        }

        // A crédito: la parte no pagada se documenta como una forma de pago "Crédito".
        if (! empty($d['credito']['es'])) {
            $faltante = round($totalPagar - $sumaDoc, 2);
            if ($faltante > 0.009) {
                $out[] = [
                    'Descripcion' => 'Crédito',
                    'Fecha' => (string) $d['fecha'],
                    'Forma' => '07',
                    'Monto' => $this->q2($faltante),
                    'Moneda' => $moneda,
                    'TipoCambio' => '0.0000',
                ];
                $sumaDoc += $faltante;
            }
        }

        if (! $out) {
            $out[] = [
                'Descripcion' => ! empty($d['credito']['es']) ? 'Crédito' : 'Efectivo',
                'Fecha' => (string) $d['fecha'],
                'Forma' => ! empty($d['credito']['es']) ? '07' : '01',
                'Monto' => $this->q2($totalPagar),
                'Moneda' => $moneda,
                'TipoCambio' => '0.0000',
            ];
        } else {
            // Cuadre fino: la suma de las formas debe igualar TotalAPagar en moneda del documento.
            $dif = round($totalPagar - $sumaDoc, 2);
            if (abs($dif) >= 0.01) {
                $i = count($out) - 1;
                $factor = (float) $out[$i]['TipoCambio'];
                if ($factor <= 0) {
                    $factor = 1;
                }
                $out[$i]['Monto'] = $this->q2((float) $out[$i]['Monto'] + $dif / $factor);
            }
        }

        return $out;
    }

    private function formaPago(?string $codigo, string $nombre, string $moneda): string
    {
        $codigo = trim((string) $codigo);
        if (preg_match('/^(0[1-9]|1[0-8]|99)$/', $codigo)) {
            return $codigo;
        }
        $n = mb_strtolower($nombre);
        if (str_contains($n, 'pago') && (str_contains($n, 'movil') || str_contains($n, 'móvil'))) {
            return '02';
        }
        if (str_contains($n, 'transfer')) {
            return '03';
        }
        if (str_contains($n, 'divisa')) {
            return '09';
        }
        if (str_contains($n, 'efectivo') && ! in_array($moneda, ['VES', 'BSD'], true)) {
            return '09';
        }
        if (str_contains($n, 'efectivo')) {
            return '01';
        }
        if (str_contains($n, 'crédito') || str_contains($n, 'credito')) {
            return '04';
        }
        if (str_contains($n, 'débito') || str_contains($n, 'debito')) {
            return '05';
        }
        if (str_contains($n, 'cheque')) {
            return '06';
        }
        if (str_contains($n, 'tarjeta')) {
            return '04';
        }
        if (str_contains($n, 'movil') || str_contains($n, 'móvil')) {
            return '02';
        }

        return '07';
    }

    /* ───────────── Info adicional ───────────── */

    private function infoAdicional(array $d, array $cfg, bool $esNota): array
    {
        $out = [];
        if ($esNota) {
            $venta = $d['venta'] ?? [];
            $out[] = ['campo' => 'Documento afectado', 'valor' => (string) ($venta['factura'] ?? '')];
            $out[] = ['campo' => 'Motivo', 'valor' => $this->texto($d['motivo'] ?? '', 255)];
        } else {
            $out[] = ['campo' => 'Venta interna', 'valor' => (string) ($d['numero'] ?? '')];
        }
        if (! empty($d['regimen']['especial'])) {
            $out[] = ['campo' => 'Contribuyente Especial', 'valor' => 'Sí'];
        }
        if (! empty($d['regimen']['precios_incluyen_iva'])) {
            $out[] = ['campo' => 'Precios', 'valor' => 'IVA incluido'];
        }

        return array_values(array_filter($out, fn ($x) => trim((string) $x['valor']) !== ''));
    }

    /* ───────────── Utilidades ───────────── */

    private function impuestoNota(array $d): string
    {
        if ((float) ($d['iva_ves'] ?? 0) > 0) {
            return 'G';
        }
        $hayF = false;
        $hayE = false;
        foreach (($d['venta']['lineas'] ?? []) as $l) {
            if (($l['alicuota'] ?? '') === 'F') {
                $hayF = true;
            } elseif (($l['alicuota'] ?? '') === 'E') {
                $hayE = true;
            }
        }

        return $hayF && ! $hayE ? 'F' : 'E';
    }

    private function moneda(array $cfg): string
    {
        $m = strtoupper(trim((string) ($cfg['moneda'] ?? 'BSD')));
        if ($m === 'VES') {
            $m = 'BSD';
        }

        return in_array($m, ['BSD', 'USD', 'EUR'], true) ? $m : 'BSD';
    }

    private function letras(float $monto, string $moneda): string
    {
        $txt = match ($moneda) {
            'USD' => NumeroALetras::moneda($monto, 'dólar', 'dólares'),
            'EUR' => NumeroALetras::moneda($monto, 'euro', 'euros'),
            default => NumeroALetras::bolivares($monto),
        };

        return $this->texto($txt, 255);
    }

    private function horaFiscal(array $d): string
    {
        $h = trim((string) ($d['hora_fiscal'] ?? ''));
        if (preg_match('/^(0[1-9]|1[0-2]):[0-5][0-9]:[0-5][0-9] [ap]m$/', $h)) {
            return $h;
        }
        $t = trim((string) ($d['hora'] ?? '00:00'));
        [$hh, $mm] = array_pad(explode(':', $t), 2, '00');
        $suf = ((int) $hh) >= 12 ? 'pm' : 'am';
        $h12 = ((int) $hh) % 12;
        if ($h12 === 0) {
            $h12 = 12;
        }

        return sprintf('%02d:%02d:00 %s', $h12, (int) $mm, $suf);
    }

    private function unidad(string $u): string
    {
        $u = strtoupper(trim($u));
        $map = [
            'UND' => 'UNI', 'UNI' => 'UNI', 'UN' => 'UNI', 'U' => 'UNI', 'PZA' => 'UNI', 'PIEZA' => 'UNI',
            'KG' => 'KG', 'KGS' => 'KG', 'KILO' => 'KG',
            'G' => 'G', 'GR' => 'G', 'GRAMO' => 'G',
            'LT' => 'LT', 'L' => 'LT', 'LTS' => 'LT', 'LITRO' => 'LT',
            'MT' => 'MT', 'M' => 'MT', 'MTS' => 'MT', 'METRO' => 'MT',
            'M2' => 'M2', 'M3' => 'M3',
            'CJ' => 'CJ', 'CAJA' => 'CJ',
            'PAR' => 'PAR', 'JGO' => 'JGO', 'JUE' => 'JGO',
            'GAL' => 'GAL', 'GALON' => 'GAL',
            'DOC' => 'DOC', 'DOCENA' => 'DOC',
            'BUL' => 'BUL', 'BULTO' => 'BUL',
            'SAC' => 'SAC', 'SACO' => 'SAC',
            'BOT' => 'BOT', 'BOTELLA' => 'BOT',
            'LAT' => 'LAT', 'LATA' => 'LAT',
        ];
        $code = $map[$u] ?? substr($u, 0, 3);

        return $code !== '' ? $code : 'UNI';
    }

    private function cantidad(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    private function tasa($pct): string
    {
        $s = rtrim(rtrim(number_format((float) $pct, 2, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    private function q2($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function q4($v): string
    {
        return number_format((float) $v, 4, '.', '');
    }

    private function soloDigitos(string $s): string
    {
        $d = preg_replace('/\D/', '', $s);

        return $d !== '' ? $d : '0';
    }

    private function texto($s, int $max): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        if ($s === '') {
            return null;
        }

        return mb_substr($s, 0, $max);
    }

    private function limpio(string $s, int $max): string
    {
        return (string) $this->texto($s, $max);
    }
}