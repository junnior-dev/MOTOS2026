-- =============================================================================
--  Archivo 41 · FACTURACIÓN DIGITAL TFHKA (The Factory HKA Venezuela)
-- =============================================================================
--  Qué agrega:
--    · tfhka_config   → credenciales y datos de emisión POR NEGOCIO (multiinquilino)
--    · tfhka_eventos  → bitácora de cada llamado a la API (auditoría y reintentos)
--    · columnas en ventas y notas_ajuste para guardar el número de control,
--      la URL oficial de consulta (QR) y el PDF oficial en Base64/bytea.
--    · metodos_pago.tfhka_forma → código de forma de pago que exige TFHKA.
--
--  Requiere: 01..39 y 31_multitenant.sql (RLS). Es idempotente.
--  Uso:
--     psql -U postgres -d tienda -v ON_ERROR_STOP=1 -f 41_tfhka_facturacion.sql
-- =============================================================================

SET search_path = tienda, public;

-- ── 1 · Configuración por negocio ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tfhka_config (
    negocio_id            SMALLINT      PRIMARY KEY REFERENCES negocio(id) ON DELETE CASCADE,
    activo                BOOLEAN       NOT NULL DEFAULT FALSE,
    ambiente              VARCHAR(12)   NOT NULL DEFAULT 'DEMO' CHECK (ambiente IN ('DEMO','PRODUCCION')),
    base_url              VARCHAR(200)  NOT NULL DEFAULT 'https://demoemisionv2.thefactoryhka.com.ve',
    usuario               VARCHAR(150),
    clave_cifrada         TEXT,          -- cifrada con APP_KEY (Laravel Crypt); NUNCA en claro
    serie                 VARCHAR(20)   NOT NULL DEFAULT '',
    sucursal              VARCHAR(20)   NOT NULL DEFAULT '',
    codigo_ciiu           VARCHAR(10)   NOT NULL DEFAULT '4663',
    codigo_plu_default    VARCHAR(20),
    moneda                VARCHAR(3)    NOT NULL DEFAULT 'BSD',
    regimen_esp_tributacion VARCHAR(55),
    usar_datos_negocio    BOOLEAN       NOT NULL DEFAULT TRUE,
    auto_emitir           BOOLEAN       NOT NULL DEFAULT TRUE,
    siguiente_nota_fiscal BIGINT        NOT NULL DEFAULT 1 CHECK (siguiente_nota_fiscal > 0),
    timeout_segundos      SMALLINT      NOT NULL DEFAULT 60 CHECK (timeout_segundos BETWEEN 5 AND 300),
    ultima_prueba_en      TIMESTAMPTZ,
    ultima_prueba_ok      BOOLEAN,
    ultima_prueba_mensaje TEXT,
    created_at            TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ   NOT NULL DEFAULT now()
);
COMMENT ON TABLE tfhka_config IS 'Credenciales de facturación digital TFHKA por negocio. La clave se guarda cifrada por Laravel (APP_KEY).';
COMMENT ON COLUMN tfhka_config.base_url IS 'Demo: https://demoemisionv2.thefactoryhka.com.ve · Producción: https://emision.thefactoryhka.com.ve.';
COMMENT ON COLUMN tfhka_config.clave_cifrada IS 'Cifrada con Crypt::encryptString. No se devuelve nunca al navegador.';
COMMENT ON COLUMN tfhka_config.auto_emitir IS 'TRUE = el POS intenta emitir la factura al confirmar la venta. FALSE = queda pendiente y se emite con el botón.';
COMMENT ON COLUMN tfhka_config.moneda IS 'Moneda del documento fiscal: BSD (bolívar) por defecto. Las formas de pago conservan su moneda original.';
COMMENT ON COLUMN tfhka_config.siguiente_nota_fiscal IS 'Contador de números de documento de las notas (solo dígitos, como exige TFHKA).';

-- Columnas nuevas si la tabla ya existía de una versión anterior.
ALTER TABLE tfhka_config ADD COLUMN IF NOT EXISTS moneda VARCHAR(3) NOT NULL DEFAULT 'BSD';
ALTER TABLE tfhka_config ADD COLUMN IF NOT EXISTS regimen_esp_tributacion VARCHAR(55);
ALTER TABLE tfhka_config ADD COLUMN IF NOT EXISTS siguiente_nota_fiscal BIGINT NOT NULL DEFAULT 1;

-- ── 2 · Bitácora de llamados (una fila por intento) ──────────────────────────
CREATE TABLE IF NOT EXISTS tfhka_eventos (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    negocio_id     SMALLINT      NOT NULL REFERENCES negocio(id) ON DELETE CASCADE,
    venta_id       BIGINT        REFERENCES ventas(id) ON DELETE SET NULL,
    nota_id        BIGINT        REFERENCES notas_ajuste(id) ON DELETE SET NULL,
    operacion      VARCHAR(20)   NOT NULL CHECK (operacion IN ('AUTENTICAR','EMITIR','ANULAR','PDF','ESTADO','PRUEBA')),
    tipo_documento VARCHAR(2),
    numero_documento VARCHAR(40),
    estado         VARCHAR(12)   NOT NULL CHECK (estado IN ('OK','ERROR')),
    mensaje        TEXT,
    payload        JSONB,
    respuesta      JSONB,
    usuario_id     BIGINT,
    creado_en      TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_tfhka_eventos_negocio ON tfhka_eventos (negocio_id, creado_en DESC);
CREATE INDEX IF NOT EXISTS ix_tfhka_eventos_venta   ON tfhka_eventos (venta_id);
CREATE INDEX IF NOT EXISTS ix_tfhka_eventos_nota    ON tfhka_eventos (nota_id);
COMMENT ON TABLE tfhka_eventos IS 'Auditoría de cada llamado a TFHKA: qué se envió, qué respondió y si falló. Sirve para reintentar sin adivinar.';

-- ── 3 · Datos fiscales en la venta ───────────────────────────────────────────
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS url_consulta       TEXT;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS pdf_fiscal         BYTEA;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS tfhka_estado       VARCHAR(20);
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS tfhka_respuesta    JSONB;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS tfhka_emitida_en   TIMESTAMPTZ;
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS tfhka_anulada_en   TIMESTAMPTZ;
COMMENT ON COLUMN ventas.url_consulta IS 'URL oficial de verificación devuelta por TFHKA; es la que va en el QR.';
COMMENT ON COLUMN ventas.pdf_fiscal IS 'PDF oficial de TFHKA en bytea (Base64 decodificado) para reimprimir sin volver a llamar a la API.';
COMMENT ON COLUMN ventas.tfhka_estado IS 'PENDIENTE / EMITIDA / ERROR / ANULADA. NULL = la venta no usa facturación digital.';

-- ── 4 · Datos fiscales en las notas de crédito/débito ────────────────────────
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS numero_fiscal   VARCHAR(40);
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS url_consulta    TEXT;
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS pdf_fiscal      BYTEA;
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS tfhka_estado    VARCHAR(20);
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS tfhka_respuesta JSONB;
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS tfhka_emitida_en TIMESTAMPTZ;
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS tfhka_anulada_en TIMESTAMPTZ;
COMMENT ON COLUMN notas_ajuste.numero_fiscal IS 'Número de documento fiscal enviado a TFHKA (solo dígitos, como exige la API). El número interno NC-/ND- se conserva en numero.';
-- Tienda única (sin negocio_id): la unicidad del número fiscal es por tipo de nota.
-- En el portal multiinquilino se ampliará a (negocio_id, tipo, numero_fiscal).
CREATE UNIQUE INDEX IF NOT EXISTS uq_notas_fiscal
    ON notas_ajuste (tipo, numero_fiscal)
    WHERE numero_fiscal IS NOT NULL;

-- ── 5 · Forma de pago TFHKA en los métodos del negocio ───────────────────────
ALTER TABLE metodos_pago ADD COLUMN IF NOT EXISTS tfhka_forma VARCHAR(2);
COMMENT ON COLUMN metodos_pago.tfhka_forma IS 'Código de forma de pago de TFHKA: 01 efectivo, 02 pago móvil, 03 transferencia, 04 tarjeta de crédito, 05 tarjeta de débito, 06 cheque, 07 otros, 08 tarjeta de regalo, 09 efectivo divisa. Vacío = se deduce por el nombre/moneda.';

-- ── 5b · Permitir guardar los metadatos fiscales sin romper la inmutabilidad ─
-- La guarda de ventas solo dejaba fijar numero_factura_fiscal y numero_control.
-- Se amplía a los campos de TFHKA (URL, PDF, estado, respuesta), sin permitir
-- tocar importes, líneas, cliente ni pagos de un documento ya emitido.
CREATE OR REPLACE FUNCTION fn_solo_asigna_factura_fiscal(p_old ventas, p_new ventas) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE n ventas := p_new;
BEGIN
    IF p_old.estado IS DISTINCT FROM 'COMPLETADA' OR p_new.estado IS DISTINCT FROM 'COMPLETADA' THEN RETURN false; END IF;
    n.numero_factura_fiscal := p_old.numero_factura_fiscal;
    n.numero_control := p_old.numero_control;
    n.url_consulta := p_old.url_consulta;
    n.pdf_fiscal := p_old.pdf_fiscal;
    n.tfhka_estado := p_old.tfhka_estado;
    n.tfhka_respuesta := p_old.tfhka_respuesta;
    n.tfhka_emitida_en := p_old.tfhka_emitida_en;
    n.tfhka_anulada_en := p_old.tfhka_anulada_en;
    IF n IS DISTINCT FROM p_old THEN RETURN false; END IF;
    RETURN (p_old.numero_factura_fiscal IS NULL OR p_new.numero_factura_fiscal IS NOT DISTINCT FROM p_old.numero_factura_fiscal)
       AND (p_old.numero_control IS NULL OR p_new.numero_control IS NOT DISTINCT FROM p_old.numero_control);
END $$;
COMMENT ON FUNCTION fn_solo_asigna_factura_fiscal(ventas, ventas) IS 'TRUE si el UPDATE solo fija metadatos fiscales de TFHKA (número, control, URL, PDF, estado) una vez emitida la venta.';

-- Las notas de ajuste siguen siendo inmutables salvo por sus metadatos fiscales.
CREATE OR REPLACE FUNCTION trg_notas_ajuste_fiscal() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE n notas_ajuste := NEW;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'No se puede borrar una nota de ajuste: anúlela' USING ERRCODE = 'TV005';
    END IF;
    n.numero_fiscal := OLD.numero_fiscal;
    n.numero_control := OLD.numero_control;
    n.url_consulta := OLD.url_consulta;
    n.pdf_fiscal := OLD.pdf_fiscal;
    n.tfhka_estado := OLD.tfhka_estado;
    n.tfhka_respuesta := OLD.tfhka_respuesta;
    n.tfhka_emitida_en := OLD.tfhka_emitida_en;
    n.tfhka_anulada_en := OLD.tfhka_anulada_en;
    IF n IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'La nota de ajuste no se puede modificar: solo se permite registrar su emisión fiscal' USING ERRCODE = 'TV005';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS trg_notas_ajuste_inmutable ON notas_ajuste;
CREATE TRIGGER trg_notas_ajuste_inmutable BEFORE UPDATE OR DELETE ON notas_ajuste
    FOR EACH ROW EXECUTE FUNCTION trg_notas_ajuste_fiscal();
COMMENT ON FUNCTION trg_notas_ajuste_fiscal() IS 'Bloquea cambios en una nota de ajuste salvo numero_fiscal, control, URL, PDF y estado de TFHKA.';

-- ── 5c · Negocio activo (tienda única) ──────────────────────────────────────
-- Los servicios de TFHKA hablan de «negocio actual» para el SaaS; en tienda única
-- siempre es la única fila de negocio. El portal la redefinirá por sesión.
CREATE OR REPLACE FUNCTION fn_negocio_actual() RETURNS smallint LANGUAGE sql STABLE AS
$$ SELECT id FROM negocio ORDER BY id LIMIT 1 $$;
COMMENT ON FUNCTION fn_negocio_actual() IS 'Negocio activo: la única fila de negocio (tienda única); el portal la redefinirá por sesión.';

-- ── 6 · Datos iniciales: ninguna tienda queda activa hasta probar credenciales
INSERT INTO tfhka_config (negocio_id)
SELECT id FROM negocio
ON CONFLICT (negocio_id) DO NOTHING;

-- ── 7 · RLS + sello para las tablas nuevas (patrón de 31_multitenant.sql) ────
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'fn_sello_negocio') THEN
        DROP TRIGGER IF EXISTS trg_sello_negocio ON tfhka_config;
        CREATE TRIGGER trg_sello_negocio BEFORE INSERT ON tfhka_config
            FOR EACH ROW EXECUTE FUNCTION fn_sello_negocio();
        DROP TRIGGER IF EXISTS trg_sello_negocio ON tfhka_eventos;
        CREATE TRIGGER trg_sello_negocio BEFORE INSERT ON tfhka_eventos
            FOR EACH ROW EXECUTE FUNCTION fn_sello_negocio();
    END IF;
END $$;

DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'fn_sesion_permitida') THEN
        ALTER TABLE tfhka_config ENABLE ROW LEVEL SECURITY;
        ALTER TABLE tfhka_config FORCE ROW LEVEL SECURITY;
        DROP POLICY IF EXISTS p_tenant ON tfhka_config;
        CREATE POLICY p_tenant ON tfhka_config
            USING (negocio_id = fn_negocio_actual() AND (SELECT fn_sesion_permitida()))
            WITH CHECK (negocio_id = fn_negocio_actual() AND (SELECT fn_sesion_permitida()));

        ALTER TABLE tfhka_eventos ENABLE ROW LEVEL SECURITY;
        ALTER TABLE tfhka_eventos FORCE ROW LEVEL SECURITY;
        DROP POLICY IF EXISTS p_tenant ON tfhka_eventos;
        CREATE POLICY p_tenant ON tfhka_eventos
            USING (negocio_id = fn_negocio_actual() AND (SELECT fn_sesion_permitida()))
            WITH CHECK (negocio_id = fn_negocio_actual() AND (SELECT fn_sesion_permitida()));
    END IF;
END $$;

-- ── 8 · Permisos para el rol de la app ──────────────────────────────────────
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'saas_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON tfhka_config, tfhka_eventos TO saas_app;
        GRANT USAGE, SELECT ON SEQUENCE tfhka_eventos_id_seq TO saas_app;
    END IF;
END $$;

\echo '>> 41 TFHKA listo. Configura usuario/clave en Configuración → Facturación digital.'