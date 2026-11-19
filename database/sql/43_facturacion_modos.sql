-- =============================================================================
--  Archivo 43 · MODOS DE FACTURACIÓN FISCAL (SENIAT)
-- =============================================================================
--  Qué agrega:
--    · facturacion_config → el MODO de emisión fiscal del negocio (1 fila):
--         IMPRENTA_DIGITAL → la imprenta digital autorizada (TFHKA) emite por API
--                            y asigna el número de control (Providencia
--                            SNAT/2024/000102, G.O. 43.032: el número de control
--                            lo asigna la imprenta digital, consecutivo y único).
--         MAQUINA_FISCAL   → el sistema envía el documento a la impresora fiscal
--                            autorizada (TCP, driver HTTP o SIMULADOR de pruebas);
--                            la máquina imprime y reporta su número de registro y
--                            el número de control, que el sistema registra tal cual.
--         MANUAL           → numeración local del negocio (SQL 09) con asignación
--                            a mano del número de factura y de control.
--    · fuente_fiscal en ventas y notas_ajuste → quién asignó el número fiscal
--      (TFHKA / MAQUINA_FISCAL / MANUAL): auditoría del origen del control.
--
--  Requiere 41_tfhka_facturacion.sql. Es idempotente.
-- =============================================================================

SET search_path = tienda, public;

-- ── 1 · Configuración del modo de emisión (una sola fila) ───────────────────
CREATE TABLE IF NOT EXISTS facturacion_config (
    id                   SMALLINT     NOT NULL PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    modo                 VARCHAR(20)  NOT NULL DEFAULT 'IMPRENTA_DIGITAL'
                         CHECK (modo IN ('IMPRENTA_DIGITAL', 'MAQUINA_FISCAL', 'MANUAL')),
    -- Máquina fiscal: número de registro ante el SENIAT (identidad única del equipo)
    maquina_registro     VARCHAR(30),
    -- Dónde está la máquina o su driver
    maquina_ip           VARCHAR(120),
    maquina_puerto       INTEGER      NOT NULL DEFAULT 9100,
    -- TCP = socket directo del protocolo de la máquina · HTTP = driver local
    -- SIMULADOR = asigna números con el formato de máquina fiscal para pruebas
    maquina_protocolo    VARCHAR(20)  NOT NULL DEFAULT 'TCP'
                         CHECK (maquina_protocolo IN ('TCP', 'HTTP', 'SIMULADOR')),
    -- Datos de emisión clásicos de factura impresa
    punto_emision        VARCHAR(4),
    oficina              VARCHAR(4),
    serie                VARCHAR(10),
    -- Correlativos del simulador (nunca se usan con máquina real)
    siguiente_factura    BIGINT       NOT NULL DEFAULT 1,
    siguiente_nota       BIGINT       NOT NULL DEFAULT 1,
    activo               BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (modo <> 'MAQUINA_FISCAL' OR maquina_protocolo <> 'TCP' OR maquina_ip IS NOT NULL)
);

INSERT INTO facturacion_config (id) VALUES (1)
ON CONFLICT (id) DO NOTHING;

COMMENT ON TABLE facturacion_config IS 'Modo de emisión fiscal: IMPRENTA_DIGITAL (TFHKA por API), MAQUINA_FISCAL (impresora autorizada) o MANUAL (numeración local).';

-- ── 2 · Origen del número fiscal en ventas y notas ──────────────────────────
ALTER TABLE ventas ADD COLUMN IF NOT EXISTS fuente_fiscal VARCHAR(20);
COMMENT ON COLUMN ventas.fuente_fiscal IS 'Quién asignó el número de control: TFHKA (imprenta digital), MAQUINA_FISCAL o MANUAL.';
ALTER TABLE notas_ajuste ADD COLUMN IF NOT EXISTS fuente_fiscal VARCHAR(20);
COMMENT ON COLUMN notas_ajuste.fuente_fiscal IS 'Quién asignó el número fiscal de la nota: TFHKA, MAQUINA_FISCAL o MANUAL.';

-- Las ventas ya emitidas por la API quedan con su fuente registrada.
UPDATE ventas SET fuente_fiscal = 'TFHKA'
WHERE numero_factura_fiscal IS NOT NULL AND tfhka_estado = 'EMITIDA' AND fuente_fiscal IS NULL;

-- ── 3 · El blindaje fiscal admite la nueva columna de auditoría ─────────────
-- Las guardas de ventas y notas (41) ya permiten registrar la emisión fiscal;
-- se redefinen para incluir fuente_fiscal (quién asignó el número) sin aflojar
-- ninguna otra regla: el resto de columnas sigue inmutable.
CREATE OR REPLACE FUNCTION fn_solo_asigna_factura_fiscal(p_old ventas, p_new ventas) RETURNS boolean LANGUAGE plpgsql AS
$fn$
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
    n.fuente_fiscal := p_old.fuente_fiscal;
    IF n IS DISTINCT FROM p_old THEN RETURN false; END IF;
    RETURN (p_old.numero_factura_fiscal IS NULL OR p_new.numero_factura_fiscal IS NOT DISTINCT FROM p_old.numero_factura_fiscal)
       AND (p_old.numero_control IS NULL OR p_new.numero_control IS NOT DISTINCT FROM p_old.numero_control);
END
$fn$;

CREATE OR REPLACE FUNCTION trg_notas_ajuste_fiscal() RETURNS trigger LANGUAGE plpgsql AS
$fn$
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
    n.fuente_fiscal := OLD.fuente_fiscal;
    IF n IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'La nota de ajuste no se puede modificar: solo se permite registrar su emisión fiscal' USING ERRCODE = 'TV005';
    END IF;
    RETURN NEW;
END
$fn$;

\echo '>> 43 Modos de facturación listos: IMPRENTA_DIGITAL, MAQUINA_FISCAL o MANUAL.'
