-- =============================================================================
--  14c · Snapshot de cierre: tasas congeladas + desglose bancario + precisión por moneda
-- =============================================================================
--  Completa el snapshot inmutable de cierres_caja (SQL 07) con lo que la auditoría
--  del módulo multimoneda (USD, VES, EUR, COP) dejó como pendiente:
--    · tasas: tasas de cambio vigentes AL MOMENTAR DE CERRAR (BCV USD/EUR + tasa COP),
--      congeladas para que el historial sea autocontenido y auditable.
--    · banco: desglose de los pagos NO en efectivo del turno por método y moneda
--      nativa (hoy solo vive anidado dentro de detalle; columna propia = consulta directa).
--    · monedas.decimales: precisión de conteo y despliegue por moneda (COP sin céntimos).
--  El trigger trg_cierres_caja_inmutable (SQL 07) mantiene las columnas nuevas igual de
--  inmutables: ni UPDATE ni DELETE. Idempotente:
--      psql -d tienda -v ON_ERROR_STOP=1 -f 14c_snapshot_cierre.sql
-- =============================================================================
SET search_path = tienda, public;

ALTER TABLE cierres_caja ADD COLUMN IF NOT EXISTS tasas jsonb;
ALTER TABLE cierres_caja ADD COLUMN IF NOT EXISTS banco jsonb NOT NULL DEFAULT '[]'::jsonb;

COMMENT ON COLUMN cierres_caja.tasas IS 'Tasas vigentes al cerrar el turno: {"USD":{"tasa":…,"fuente":…,"fecha":…},"EUR":…,"COP":…}. Se congelan: el historial no se recalcula.';
COMMENT ON COLUMN cierres_caja.banco IS 'Pagos que no van a la gaveta, por método y moneda nativa: [{"metodo":"Punto de Venta","moneda":"VES","n":2,"cobrado":…,"usd":…,"ves":…}].';

-- Precisión por moneda: 0 para COP (los pesos colombianos se cuentan sin céntimos),
-- 2 para el resto. La usa el conteo del arqueo (redondeo con bcmath) y el despliegue.
ALTER TABLE monedas ADD COLUMN IF NOT EXISTS decimales smallint NOT NULL DEFAULT 2 CHECK (decimales IN (0, 2));
UPDATE monedas SET decimales = 0 WHERE codigo = 'COP' AND decimales <> 0;
COMMENT ON COLUMN monedas.decimales IS 'Decimales de conteo y despliegue: COP = 0 (billetes y monedas sin céntimos), resto = 2.';
