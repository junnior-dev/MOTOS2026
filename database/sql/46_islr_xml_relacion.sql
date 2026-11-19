-- ═══════════════════════════════════════════════════════════════════════════════
-- 46 · RELACIÓN INFORMATIVA XML DE RETENCIONES DE ISLR (Portal Fiscal del SENIAT)
-- ═══════════════════════════════════════════════════════════════════════════════
-- Genera el archivo XML de la «Relación Informativa de Salarios y Otras Retenciones»
-- que el agente de retención presenta por el Portal Fiscal del SENIAT:
--
--   Manual técnico TRI.GR.03.0013 «Retenciones de ISLR. Relación informativa»
--   (Providencia N° 0299 del 12/12/2008, G.O. 39.079; porcentajes del Decreto 1.808,
--   G.O. 36.203 del 23/04/1997; canal exclusivo: Portal Fiscal, seniat.gob.ve).
--
-- Reglas del manual implementadas por fn_retenciones_islr_xml():
--   · Raíz RelacionRetencionesISLR con ATRIBUTOS RifAgente (t_Rif: 1 letra V/E/J/P/G
--     + 9 dígitos, sin guiones) y Periodo (AAAAMM).
--   · Un <DetalleRetencion> por retención ACTIVA del período (ANULADAS fuera);
--     el orden de los elementos es un xs:sequence estricto.
--   · RifRetenido: mismo patrón t_Rif del beneficiario retenido.
--   · NumeroFactura: máx 10 alfanuméricos → si tiene más, los ÚLTIMOS diez;
--     sin factura → «0».
--   · NumeroControl: SOLO el secuencial numérico (máx 8 dígitos: del formato
--     clásico 0001-00001234 sale 00001234); si no existe → «NA».
--   · CodigoConcepto: exactamente 3 dígitos del Anexo 6.1 (Decreto 1.808),
--     según el tipo de beneficiario: persona natural → código PN,
--     persona jurídica → código PJ.
--   · MontoOperacion: total de la operación en Bs con 2 decimales y punto
--     decimal (base imponible de la retención).
--   · PorcentajeRetencion: 0..100 con 2 decimales (incluye el 34 % del
--     Parágrafo Cuarto art. 9 para pagos a sujetos pasivos especiales:
--     se reporta con el código del concepto y % = 34).
--   · Sustraendo (Parágrafo Segundo art. 9): el sistema lo aplica al
--     registrar (fn_islr_calcular); la relación informativa reporta la
--     retención resultante, no el sustraendo.
--   · Los pagos informativos SIN retención se declaran con % = 0; aquí solo
--     viven las retenciones practicadas por la tienda (todas con ret > 0).
--
-- Códigos del Anexo 6.1 de los conceptos de la tienda (tabla de retenciones
-- vigente: persona natural residente «PNR» y persona jurídica domiciliada «PJD»):
--   HONORARIOS           → 002 (3 %, con sustraendo)   / 004 (5 %)   [9.1.b]
--   COMISIONES           → 018 (3 %, con sustraendo)   / 020 (5 %)   [9.2.b]
--   CONTRATISTAS         → 053 (1 %, con sustraendo)   / 055 (2 %)   [9.11]
--   ALQUILER_INMUEBLES   → 057 (3 %, con sustraendo)   / 059 (5 %)   [9.12]
--   ALQUILER_MUEBLES     → 061 (3 %, con sustraendo)   / 063 (5 %)   [9.13]
--   FLETES               → 071 (1 %, con sustraendo)   / 072 (3 %)   [9.15]
-- Los códigos PN de esta lista (002, 018, 053, 057, 061, 071) son justamente los
-- que el manual declara con sustraendo automático del Decreto 1.808.
-- ═══════════════════════════════════════════════════════════════════════════════

SET search_path = tienda, public;

-- El código SENIAT del concepto, por tipo de beneficiario (3 dígitos del Anexo 6.1).
ALTER TABLE islr_conceptos ADD COLUMN IF NOT EXISTS codigo_seniat_pn varchar(3);
ALTER TABLE islr_conceptos ADD COLUMN IF NOT EXISTS codigo_seniat_pj varchar(3);

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_islr_concepto_seniat') THEN
        ALTER TABLE islr_conceptos ADD CONSTRAINT chk_islr_concepto_seniat
            CHECK ((codigo_seniat_pn IS NULL OR codigo_seniat_pn ~ '^[0-9]{3}$')
               AND (codigo_seniat_pj IS NULL OR codigo_seniat_pj ~ '^[0-9]{3}$'));
    END IF;
END $$;

UPDATE islr_conceptos SET codigo_seniat_pn = c.codigo_pn, codigo_seniat_pj = c.codigo_pj
FROM (VALUES
    ('HONORARIOS',         '002', '004'),
    ('COMISIONES',         '018', '020'),
    ('CONTRATISTAS',       '053', '055'),
    ('ALQUILER_INMUEBLES', '057', '059'),
    ('ALQUILER_MUEBLES',   '061', '063'),
    ('FLETES',             '071', '072')
) AS c(codigo, codigo_pn, codigo_pj)
WHERE islr_conceptos.codigo = c.codigo;

COMMENT ON COLUMN islr_conceptos.codigo_seniat_pn IS 'Código del Anexo 6.1 (Decreto 1.808) del concepto para persona NATURAL residente, 3 dígitos.';
COMMENT ON COLUMN islr_conceptos.codigo_seniat_pj IS 'Código del Anexo 6.1 (Decreto 1.808) del concepto para persona JURÍDICA domiciliada, 3 dígitos.';

-- La relación informativa completa del período (AAAAMM), lista para el Portal Fiscal.
-- Devuelve NULL si el período no tiene retenciones ACTIVAS (el XSD exige al menos un
-- DetalleRetencion: un archivo vacío sería rechazado por el Portal).
CREATE OR REPLACE FUNCTION fn_retenciones_islr_xml(p_ano int, p_mes int) RETURNS text
LANGUAGE plpgsql STABLE AS $$
DECLARE
    rif_agente text; desde date; hasta date; n int := 0;
    r record; fac text; ctrl text; cod text; out text;
BEGIN
    IF p_ano IS NULL OR p_ano NOT BETWEEN 2000 AND 2199 THEN
        RAISE EXCEPTION 'El año del período debe estar entre 2000 y 2199' USING ERRCODE = 'TV006';
    END IF;
    IF p_mes IS NULL OR p_mes NOT BETWEEN 1 AND 12 THEN
        RAISE EXCEPTION 'El mes del período debe estar entre 01 y 12' USING ERRCODE = 'TV006';
    END IF;

    -- RifAgente = el RIF de la tienda (agente de retención), sin guiones.
    SELECT upper(replace(rif, '-', '')) INTO rif_agente FROM negocio WHERE id = 1;
    IF rif_agente IS NULL OR rif_agente !~ '^[VvEeJjPpGg][0-9]{9}$' THEN
        RAISE EXCEPTION 'El RIF del negocio (%) no tiene el formato del agente de retención: una letra V, E, J, P o G más 9 dígitos, sin guiones', COALESCE(rif_agente, '(vacío)') USING ERRCODE = 'TV006';
    END IF;

    desde := make_date(p_ano, p_mes, 1);
    hasta := (desde + interval '1 month - 1 day')::date;

    out := '<?xml version="1.0" encoding="utf-8"?>' || chr(10)
        || '<RelacionRetencionesISLR RifAgente="' || rif_agente || '" Periodo="' || to_char(desde, 'YYYYMM') || '">';

    FOR r IN
        SELECT ret.*, c.codigo_seniat_pn, c.codigo_seniat_pj, c.nombre AS concepto_nombre,
               -- el número de control de la factura pagada vive en la compra registrada
               (SELECT comp.numero_control FROM compras comp
                 WHERE ret.documento IS NOT NULL AND upper(trim(comp.numero_factura)) = upper(trim(ret.documento))
                 ORDER BY comp.id DESC LIMIT 1) AS numero_control
          FROM retenciones_islr ret
          JOIN islr_conceptos c ON c.codigo = ret.concepto
         WHERE ret.estado = 'ACTIVA' AND ret.fecha BETWEEN desde AND hasta
         ORDER BY ret.fecha, ret.id
    LOOP
        n := n + 1;

        IF r.rif IS NULL OR upper(r.rif) !~ '^[VvEeJjPpGg][0-9]{9}$' THEN
            RAISE EXCEPTION 'La retención % tiene un RIF de beneficiario (%) que no cumple el formato del Portal Fiscal: letra V, E, J, P o G más 9 dígitos. Corrígelo antes de generar el archivo', r.numero, r.rif USING ERRCODE = 'TV006';
        END IF;

        -- NumeroFactura: máx 10 alfanuméricos; con más de 10 → los últimos diez; sin factura → «0».
        fac := regexp_replace(COALESCE(r.documento, ''), '[^a-zA-Z0-9]', '', 'g');
        fac := CASE WHEN fac = '' THEN '0' WHEN length(fac) > 10 THEN right(fac, 10) ELSE fac END;

        -- NumeroControl: SOLO el secuencial numérico, máx 8 dígitos (los últimos);
        -- del formato clásico 0001-00001234 sale 00001234. Sin número → «NA».
        ctrl := regexp_replace(COALESCE(r.numero_control, ''), '[^0-9]', '', 'g');
        ctrl := CASE WHEN ctrl = '' THEN 'NA' WHEN length(ctrl) > 8 THEN right(ctrl, 8) ELSE ctrl END;

        -- CodigoConcepto: el del Anexo 6.1 según el tipo de beneficiario.
        cod := CASE WHEN r.tipo_beneficiario = 'N' THEN r.codigo_seniat_pn ELSE r.codigo_seniat_pj END;
        IF cod IS NULL THEN
            RAISE EXCEPTION 'El concepto «%» (%) no tiene código del Anexo 6.1 asignado: complétalo en la tabla islr_conceptos antes de generar el archivo', r.concepto, r.concepto_nombre USING ERRCODE = 'TV006';
        END IF;

        out := out || chr(10) || ' <DetalleRetencion>'
            || '<RifRetenido>' || upper(r.rif) || '</RifRetenido>'
            || '<NumeroFactura>' || fac || '</NumeroFactura>'
            || '<NumeroControl>' || ctrl || '</NumeroControl>'
            || '<CodigoConcepto>' || cod || '</CodigoConcepto>'
            || '<MontoOperacion>' || r.base_ves::text || '</MontoOperacion>'
            || '<PorcentajeRetencion>' || r.porcentaje::text || '</PorcentajeRetencion>'
            || '</DetalleRetencion>';
    END LOOP;

    IF n = 0 THEN RETURN NULL; END IF;
    RETURN out || chr(10) || '</RelacionRetencionesISLR>' || chr(10);
END $$;

COMMENT ON FUNCTION fn_retenciones_islr_xml(int, int) IS 'XML de la Relación Informativa de Salarios y Otras Retenciones (SENIAT TRI.GR.03.0013) del período AAAAMM: un DetalleRetencion por retención ACTIVA, con las reglas del manual (factura últimos 10 o «0», control secuencial ≤ 8 o «NA», código Anexo 6.1 por tipo de beneficiario, montos con punto decimal). NULL si el período está vacío.';
