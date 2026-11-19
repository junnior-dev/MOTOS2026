<?php

/**
 * Facturación digital TFHKA (Imprenta Digital VE).
 *
 * Las credenciales de producción NUNCA se suben al repositorio: van en .env.
 * Para el SaaS, cada tienda puede tener su propio usuario/clave en la tabla
 * tfhka_config (cifrados con APP_KEY); estas variables son el valor por
 * defecto cuando la tienda no ha guardado los suyos.
 */
return [
    'base_url' => env('TFHKA_BASE_URL', 'https://demoemisionv2.thefactoryhka.com.ve'),
    'usuario' => env('TFHKA_USUARIO'),
    'clave' => env('TFHKA_CLAVE'),

    // Ambientes que se ofrecen en la pantalla (el base_url de la fila manda).
    'demo_url' => env('TFHKA_DEMO_URL', 'https://demoemisionv2.thefactoryhka.com.ve'),
    'produccion_url' => env('TFHKA_PROD_URL', 'https://emision.thefactoryhka.com.ve'),

    // Tiempo máximo de espera de cada llamado (segundos).
    'timeout' => (int) env('TFHKA_TIMEOUT', 60),

    // Emitir automáticamente al confirmar la venta. Se puede apagar por tienda.
    'auto_emitir' => filter_var(env('TFHKA_AUTO_EMITIR', true), FILTER_VALIDATE_BOOL),

    // Código CIIU por defecto (comercio al por menor de repuestos de vehículos).
    'codigo_ciiu' => env('TFHKA_CIIU', '4663'),

    // Moneda del documento fiscal. BSD = bolívar (recomendado por TFHKA para Venezuela).
    'moneda' => env('TFHKA_MONEDA', 'BSD'),

    // Descargar y guardar el PDF oficial después de emitir (recomendado).
    'guardar_pdf' => filter_var(env('TFHKA_GUARDAR_PDF', true), FILTER_VALIDATE_BOOL),

    /*
     * Cola de emisión (App\Jobs\EmitirDocumentoFiscal, driver «database»).
     * · cola_backoff: segundos de espera entre reintentos (creciente: red caída,
     *   TFHKA en mantenimiento…). En pruebas Pest se acorta para correr en ms.
     * · El trabajo admite cola_backoff.len intentos; agotados, la venta queda en
     *   ERROR visible en la pantalla de facturación y el comando
     *   fiscal:reintentar (agenda cada 10 min) la vuelve a encolar.
     */
    'cola_backoff' => array_map('intval', explode(',', (string) env('TFHKA_COLA_BACKOFF', '15,60,300,900,3600'))),
];