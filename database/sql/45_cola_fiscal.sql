-- =============================================================================
--  Archivo 45 · COLA DE LARAVEL (base de datos) para la emisión fiscal
-- =============================================================================
--  La factura TFHKA ya no se emite dentro del request de la venta: se encola
--  con el driver «database» (App\Jobs\EmitirDocumentoFiscal). Un corte de red
--  nunca pierde la venta ni bloquea la caja: el worker reintenta con backoff y,
--  si se agota, el documento queda ERROR/PENDIENTE visible en la pantalla de
--  facturación para el reintento manual o del comando fiscal:reintentar.
--
--  Estas tablas son INFRAESTRUCTURA (no datos del negocio): viven en el schema
--  public, fuera del esquema «tienda», para que no se mezclen en respaldos ni
--  en los clones de pruebas del negocio. SQL idempotente (IF NOT EXISTS).
--
--  Uso:
--     psql -U postgres -d tienda -v ON_ERROR_STOP=1 -f 45_cola_fiscal.sql
--  y en .env del servidor:
--     QUEUE_CONNECTION=database
--  (en pruebas Pest sigue sync: phpunit.xml; en desarrollo sin worker, sync)
-- =============================================================================

CREATE TABLE IF NOT EXISTS public.jobs (
    id            BIGSERIAL PRIMARY KEY,
    queue         VARCHAR   NOT NULL,
    payload       TEXT      NOT NULL,
    attempts      SMALLINT  NOT NULL DEFAULT 0,
    reserved_at   INTEGER,
    available_at  INTEGER   NOT NULL,
    created_at    INTEGER   NOT NULL
);

CREATE INDEX IF NOT EXISTS jobs_queue_available_at_reserved_at_index
    ON public.jobs (queue, available_at, reserved_at);

CREATE TABLE IF NOT EXISTS public.job_batches (
    id            VARCHAR     PRIMARY KEY,
    name          VARCHAR     NOT NULL,
    total_jobs    INTEGER     NOT NULL DEFAULT 0,
    pending_jobs  INTEGER     NOT NULL DEFAULT 0,
    failed_jobs   INTEGER     NOT NULL DEFAULT 0,
    failed_job_ids TEXT       NOT NULL,
    options       TEXT,
    cancelled_at  INTEGER,
    created_at    INTEGER     NOT NULL,
    finished_at   INTEGER
);

CREATE TABLE IF NOT EXISTS public.failed_jobs (
    id            BIGSERIAL PRIMARY KEY,
    uuid          VARCHAR   NOT NULL UNIQUE,
    connection    TEXT      NOT NULL,
    queue         TEXT      NOT NULL,
    payload       TEXT      NOT NULL,
    exception     TEXT      NOT NULL,
    failed_at     TIMESTAMP NOT NULL DEFAULT now()
);

COMMENT ON TABLE public.jobs IS 'Cola de Laravel (driver database): trabajos pendientes, p. ej. emisión fiscal.';
COMMENT ON TABLE public.failed_jobs IS 'Trabajos agotados (se intentaron todos los reintentos). Véase app/Jobs/EmitirDocumentoFiscal.';
