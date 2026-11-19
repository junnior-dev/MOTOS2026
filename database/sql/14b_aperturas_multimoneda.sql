-- =============================================================================
--  14b · Apertura de caja multi-moneda (USD, Bs, EUR, COP)
-- =============================================================================
--  El fondo inicial del turno puede contarse en cualquiera de las monedas que
--  maneja la tienda. Se guarda el monto convertido a las cuatro monedas con las
--  tasas vigentes del día y la moneda en que el cajero contó. Es idempotente:
--      psql -d tienda -f 14b_aperturas_multimoneda.sql
-- =============================================================================
SET search_path = tienda, public;

ALTER TABLE aperturas_caja ADD COLUMN IF NOT EXISTS fondo_inicial_moneda varchar(3) NOT NULL DEFAULT 'USD'
    CHECK (fondo_inicial_moneda IN ('USD','VES','EUR','COP'));
ALTER TABLE aperturas_caja ADD COLUMN IF NOT EXISTS fondo_inicial_eur numeric(18,2) NOT NULL DEFAULT 0 CHECK (fondo_inicial_eur >= 0);
ALTER TABLE aperturas_caja ADD COLUMN IF NOT EXISTS fondo_inicial_cop numeric(18,2) NOT NULL DEFAULT 0 CHECK (fondo_inicial_cop >= 0);

COMMENT ON COLUMN aperturas_caja.fondo_inicial_moneda IS 'Moneda en la que el cajero contó el fondo al abrir el turno (USD, VES, EUR o COP).';
COMMENT ON COLUMN aperturas_caja.fondo_inicial_eur IS 'Efectivo en euros equivalente al fondo del turno, con la tasa vigente al abrir.';
COMMENT ON COLUMN aperturas_caja.fondo_inicial_cop IS 'Efectivo en pesos colombianos equivalente al fondo del turno, con la tasa vigente al abrir.';

-- Abre el turno con el fondo en la moneda elegida; las otras tres se guardan convertidas con las tasas vigentes.
-- La firma antigua (usuario, fondo_usd, fondo_ves) sigue funcionando por los valores por defecto: se elimina
-- la versión vieja de 3 argumentos para que no queden dos funciones con el mismo nombre (ambigüedad en PG).
DROP FUNCTION IF EXISTS tienda.fn_abrir_caja(bigint, numeric, numeric);
CREATE OR REPLACE FUNCTION fn_abrir_caja(p_usuario_id bigint, p_fondo_usd numeric, p_fondo_ves numeric,
                                         p_fondo_eur numeric DEFAULT 0, p_fondo_cop numeric DEFAULT 0,
                                         p_moneda varchar DEFAULT 'USD') RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE v_id bigint;
BEGIN
    IF COALESCE(p_fondo_usd, 0) < 0 OR COALESCE(p_fondo_ves, 0) < 0 OR COALESCE(p_fondo_eur, 0) < 0 OR COALESCE(p_fondo_cop, 0) < 0
        THEN RAISE EXCEPTION 'El fondo inicial no puede ser negativo' USING ERRCODE = 'TV006'; END IF;
    IF p_moneda NOT IN ('USD','VES','EUR','COP') THEN RAISE EXCEPTION 'Moneda del fondo no soportada' USING ERRCODE = 'TV006'; END IF;
    PERFORM pg_advisory_xact_lock(hashtext('tienda.apertura.' || p_usuario_id));
    IF NOT EXISTS (SELECT 1 FROM usuarios WHERE id = p_usuario_id AND activo) THEN RAISE EXCEPTION 'El usuario no existe o está inactivo' USING ERRCODE = 'TV006'; END IF;
    IF fn_apertura_activa(p_usuario_id) IS NOT NULL THEN RAISE EXCEPTION 'Ya tienes una caja abierta. Ciérrala antes de abrir otra.' USING ERRCODE = 'TV005'; END IF;
    INSERT INTO aperturas_caja (usuario_id, fondo_inicial_usd, fondo_inicial_ves, fondo_inicial_eur, fondo_inicial_cop, fondo_inicial_moneda)
    VALUES (p_usuario_id,
            ROUND(COALESCE(p_fondo_usd, 0), 2), ROUND(COALESCE(p_fondo_ves, 0), 2),
            ROUND(COALESCE(p_fondo_eur, 0), 2), ROUND(COALESCE(p_fondo_cop, 0), 2),
            p_moneda) RETURNING id INTO v_id;
    RETURN v_id;
END $$;
