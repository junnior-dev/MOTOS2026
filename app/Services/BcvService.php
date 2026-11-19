<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Consulta directa al Banco Central de Venezuela (https://www.bcv.org.ve/).
 *
 *   1. De la portada HTML extrae USD (#dolar), EUR (#euro), CNY (#yuan), TRY (#lira),
 *      RUB (#rublo) y la Fecha Valor oficial (property="dc:date").
 *   2. De la planilla oficial del Sistema de Mercado Cambiario
 *      (/estadisticas/tipo-cambio-de-referencia-smc → archivo .xls BIFF8) extrae
 *      el Peso Colombiano (COP) y las demás monedas de referencia en Bs./M.E. (Venta/ASK).
 *   3. Si el archivo .xls del BCV no responde en ese instante y existe clave de
 *      ExchangeRate-API, calcula COP cruzando el dólar oficial del BCV.
 */
class BcvService
{
    private const HOME_URL = 'https://www.bcv.org.ve/';

    private const SMC_URL = 'https://www.bcv.org.ve/estadisticas/tipo-cambio-de-referencia-smc';

    /**
     * Devuelve las tasas oficiales del BCV en bolívares por 1 unidad de moneda (`Bs./M.E.`).
     *
     * @return array{tasas: array<string, float>, fecha: string, actualizada: string}
     */
    public function tasas(bool $forzar = false): array
    {
        if ($forzar) {
            Cache::forget('bcv.latest.tasas');
        }

        return Cache::remember('bcv.latest.tasas', now()->addMinutes(30), fn () => $this->consultar());
    }

    protected function consultar(): array
    {
        $html = $this->get(self::HOME_URL);
        if ($html === '') {
            throw new RuntimeException('El portal del BCV (www.bcv.org.ve) no respondió. Intenta de nuevo en unos segundos.');
        }

        $tasas = [];
        $mapaHome = [
            'dolar' => 'USD',
            'euro' => 'EUR',
            'yuan' => 'CNY',
            'lira' => 'TRY',
            'rublo' => 'RUB',
        ];

        foreach ($mapaHome as $id => $codigo) {
            $pos = stripos($html, 'id="'.$id.'"');
            if ($pos === false) {
                continue;
            }
            $fragmento = substr($html, $pos, 700);
            if (preg_match('/<strong[^>]*>\s*([0-9.,]+)\s*<\/strong>/i', $fragmento, $m)) {
                $val = $this->numeroBcv($m[1]);
                if ($val > 0) {
                    $tasas[$codigo] = $val;
                }
            }
        }

        $fecha = now()->timezone('America/Caracas')->toDateString();
        if (preg_match('/property="dc:date"\s+datatype="xsd:dateTime"\s+content="(\d{4}-\d{2}-\d{2})/i', $html, $f)) {
            $fecha = $f[1];
        }

        // Planilla oficial SMC (.xls) para obtener COP (Peso Colombiano) y demás divisas oficiales
        try {
            $smc = $this->consultarSmcXls();
            foreach ($smc['tasas'] as $mon => $tasa) {
                if ($tasa > 0 && ! isset($tasas[$mon])) {
                    $tasas[$mon] = $tasa;
                }
            }
            if (empty($tasas['USD']) && ! empty($smc['tasas']['USD'])) {
                $tasas['USD'] = $smc['tasas']['USD'];
            }
            if (empty($tasas['EUR']) && ! empty($smc['tasas']['EUR'])) {
                $tasas['EUR'] = $smc['tasas']['EUR'];
            }
            if (! empty($smc['fecha']) && $fecha === now()->timezone('America/Caracas')->toDateString()) {
                $fecha = $smc['fecha'];
            }
        } catch (Throwable) {
            // Si el XLS de estadísticas tarda, se mantiene lo leído de la portada.
        }

        // Respaldo para COP si el XLS del BCV no cargó: cruza el USD oficial del BCV con USD->COP
        if (empty($tasas['COP']) && ! empty($tasas['USD'])) {
            try {
                $api = app(ExchangeRateService::class);
                if ($api->configurado()) {
                    $r = $api->tasasUsd();
                    $copPorUsd = (float) ($r['rates']['COP'] ?? 0);
                    if ($copPorUsd > 0) {
                        $tasas['COP'] = round($tasas['USD'] / $copPorUsd, 8);
                    }
                }
            } catch (Throwable) {
                // sin respaldo externo
            }
        }

        if (empty($tasas['USD'])) {
            throw new RuntimeException('No se pudo leer la tasa oficial del dólar desde el portal del BCV.');
        }

        return [
            'tasas' => $tasas,
            'fecha' => $fecha,
            'actualizada' => now()->timezone('America/Caracas')->format('d/m/Y H:i'),
        ];
    }

    /**
     * Descarga y lee la primera hoja (fecha más reciente) del archivo .xls oficial
     * de Tipos de Cambio de Referencia (SMC) del BCV.
     *
     * @return array{tasas: array<string, float>, fecha: ?string}
     */
    protected function consultarSmcXls(): array
    {
        $pagina = $this->get(self::SMC_URL);
        if (! preg_match('/href="([^"]+_smc\.xls)"/i', $pagina, $m)) {
            return ['tasas' => [], 'fecha' => null];
        }

        $urlXls = $m[1];
        if (! str_starts_with($urlXls, 'http')) {
            $urlXls = 'https://www.bcv.org.ve/'.ltrim($urlXls, '/');
        }

        $bin = $this->get($urlXls);
        if (strlen($bin) < 1024) {
            return ['tasas' => [], 'fecha' => null];
        }

        return $this->parsearBiff8Smc($bin);
    }

    /**
     * Lector ligero BIFF8 (Excel 97-2003) para extraer la tabla de la primera hoja del SMC del BCV.
     *
     * @return array{tasas: array<string, float>, fecha: ?string}
     */
    protected function parsearBiff8Smc(string $bin): array
    {
        $pos = strpos($bin, "\x09\x08\x10\x00\x00\x06\x05\x00");
        if ($pos === false) {
            return ['tasas' => [], 'fecha' => null];
        }

        $len = strlen($bin);
        $sst = [];
        $filas = [];
        $enHoja = false;
        $hojasVistas = 0;

        while ($pos + 4 <= $len) {
            $rec = unpack('vid/vlen', substr($bin, $pos, 4));
            $id = $rec['id'];
            $rlen = $rec['len'];
            $data = substr($bin, $pos + 4, $rlen);
            $pos += 4 + $rlen;

            if ($id === 0x00FC) { // SST (Shared String Table)
                $u = unpack('Vtotal/Vunique', substr($data, 0, 8));
                $spos = 8;
                $slen = strlen($data);
                for ($i = 0; $i < $u['unique'] && $spos + 3 <= $slen; $i++) {
                    $chars = unpack('v', substr($data, $spos, 2))[1];
                    $flags = ord($data[$spos + 2]);
                    $spos += 3;
                    $rt = ($flags & 0x08) ? unpack('v', substr($data, $spos, 2))[1] : 0;
                    if ($flags & 0x08) {
                        $spos += 2;
                    }
                    $ext = ($flags & 0x04) ? unpack('V', substr($data, $spos, 4))[1] : 0;
                    if ($flags & 0x04) {
                        $spos += 4;
                    }
                    $es16 = ($flags & 0x01) !== 0;
                    $bytes = $chars * ($es16 ? 2 : 1);
                    $str = substr($data, $spos, $bytes);
                    $spos += $bytes + $rt * 4 + $ext;
                    $sst[$i] = $es16
                        ? mb_convert_encoding($str, 'UTF-8', 'UTF-16LE')
                        : mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
                }
            } elseif ($id === 0x0809 && $rlen >= 4) { // BOF
                $tipo = unpack('v', substr($data, 2, 2))[1];
                if ($tipo === 0x0010) {
                    $hojasVistas++;
                    $enHoja = ($hojasVistas === 1);
                }
            } elseif ($id === 0x000A) { // EOF
                if ($enHoja) {
                    break;
                }
            } elseif ($enHoja) {
                if ($id === 0x00FD && $rlen >= 10) { // LABELSST
                    $u = unpack('vrow/vcol/vxf/Vidx', $data);
                    $filas[$u['row']][$u['col']] = trim($sst[$u['idx']] ?? '');
                } elseif ($id === 0x0203 && $rlen >= 14) { // NUMBER
                    $u = unpack('vrow/vcol/vxf/dval', $data);
                    $filas[$u['row']][$u['col']] = (float) $u['val'];
                } elseif ($id === 0x027E && $rlen >= 10) { // RK
                    $u = unpack('vrow/vcol/vxf/Vrk', $data);
                    $filas[$u['row']][$u['col']] = $this->decodificarRk($u['rk']);
                }
            }
        }

        $tasas = [];
        $fecha = null;
        foreach ($filas as $cols) {
            foreach ($cols as $val) {
                if (is_string($val) && preg_match('/Fecha\s+Valor:\s*(\d{2})\/(\d{2})\/(\d{4})/i', $val, $fm)) {
                    $fecha = "{$fm[3]}-{$fm[2]}-{$fm[1]}";
                }
            }
            $cod = strtoupper(trim((string) ($cols[1] ?? '')));
            if ($cod === 'MXP') {
                $cod = 'MXN';
            }
            $askBs = isset($cols[6]) && is_numeric($cols[6]) ? (float) $cols[6] : 0.0;
            if (preg_match('/^[A-Z]{3}$/', $cod) && $askBs > 0) {
                $tasas[$cod] = round($askBs, 8);
            }
        }

        return ['tasas' => $tasas, 'fecha' => $fecha];
    }

    private function decodificarRk(int $rk): float
    {
        $div100 = ($rk & 1) !== 0;
        $esEntero = ($rk & 2) !== 0;
        if ($esEntero) {
            $val = (float) ($rk >> 2);
        } else {
            $val = unpack('d', pack('VV', 0, $rk & ~3))[1];
        }

        return $div100 ? $val / 100.0 : $val;
    }

    private function numeroBcv(string $txt): float
    {
        $limpio = str_replace(['.', ' '], '', trim($txt));
        $limpio = str_replace(',', '.', $limpio);

        return is_numeric($limpio) ? (float) $limpio : 0.0;
    }

    private function get(string $url): string
    {
        $res = Http::withoutVerifying()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'])
            ->timeout(20)
            ->retry(2, 300, throw: false)
            ->get($url);

        return $res->successful() ? (string) $res->body() : '';
    }
}