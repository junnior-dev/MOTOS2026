-- =============================================================================
--  40 · ISLR anual: tarifas oficiales modelo (T1 y T2) y revisión antes de liquidar
-- =============================================================================
--  Requiere: 20 (islr_ut, fn_islr_ut), 21/22/30 (calendario), 36 (islr_tarifa_tramos,
--  fn_islr_tramos_guardar, fn_islr_borrador) y 39 (columna contabilizada).
--  Es idempotente:  psql -d tienda -v ON_ERROR_STOP=1 -f 40_islr_tarifas_modelo.sql
--
--  Qué es y qué NO es:
--   · islr_tarifa_modelo son PLANTILLAS DE SOLO LECTURA con las tarifas oficiales del
--     art. 53 de la Ley de ISLR (reforma G.O. 6.507 Ext. del 29/12/2020): la Tarifa 1
--     para personas naturales residentes (8 tramos, 6 % al 34 %) y la Tarifa 2 para
--     sociedades de residentes (15 %, 22 % y 34 %). El contador confirma y las aplica
--     al ejercicio; luego puede ajustarlas en la pantalla (quedan en islr_tarifa_tramos).
--   · fn_islr_verificaciones NO declara nada ni sustituye al contador: es la lista de
--     revisión que la pantalla muestra antes de liquidar (UT, tarifa, datos, comprobantes,
--     ejercicios pendientes y ventana de presentación). Nunca bloquea por sí sola; los
--     bloqueos duros siguen viviendo en fn_islr_contabilizar (39).
--   · La declaración se presenta en el portal del SENIAT: aquí solo se arma y se revisa.
-- =============================================================================
SET search_path = tienda, public;

-- ── Plantillas de las tarifas oficiales (art. 53 de la Ley de ISLR) ──────────
CREATE TABLE IF NOT EXISTS islr_tarifa_modelo (
    codigo        varchar(2)    NOT NULL CHECK (codigo IN ('T1', 'T2')),
    orden         int           NOT NULL,
    desde_ut      numeric(14,2) NOT NULL CHECK (desde_ut >= 0),
    hasta_ut      numeric(14,2),                                -- NULL = sin tope
    pct           numeric(6,3)  NOT NULL CHECK (pct > 0 AND pct <= 100),
    sustraendo_ut numeric(14,2) NOT NULL DEFAULT 0 CHECK (sustraendo_ut >= 0),
    PRIMARY KEY (codigo, desde_ut)
);
COMMENT ON TABLE islr_tarifa_modelo IS 'Plantillas de solo lectura con las tarifas oficiales del art. 53 de la Ley de ISLR (T1 personas naturales residentes reformada 2020; T2 sociedades de residentes). Se aplican al ejercicio con fn_islr_tarifa_modelo_aplicar.';

-- Semilla idempotente: la plantilla se reemplaza completa en cada corrida.
--  T1 · Personas naturales residentes: 8 tramos del 6 % al 34 % (reforma G.O. 6.507 Ext. 29/12/2020).
--  T2 · Sociedades de residentes: 15 % hasta 2.000 UT, 22 % hasta 3.000 (sustraendo 140) y 34 % después (sustraendo 500).
--  Verificación aritmética: las escalas son continuas (en cada frontera, tramo anterior y nuevo arrojan el mismo impuesto).
DELETE FROM islr_tarifa_modelo;
INSERT INTO islr_tarifa_modelo (codigo, orden, desde_ut, hasta_ut, pct, sustraendo_ut) VALUES
    ('T1', 1,     0, 1000,  6,   0),
    ('T1', 2,  1000, 1500,  9,  30),
    ('T1', 3,  1500, 2000, 12,  75),
    ('T1', 4,  2000, 2500, 16, 155),
    ('T1', 5,  2500, 3000, 20, 255),
    ('T1', 6,  3000, 4000, 24, 375),
    ('T1', 7,  4000, 6000, 29, 575),
    ('T1', 8,  6000, NULL, 34, 875),
    ('T2', 1,     0, 2000, 15,   0),
    ('T2', 2,  2000, 3000, 22, 140),
    ('T2', 3,  3000, NULL, 34, 500);

-- Ambas plantillas en un solo jsonb para poblar la pantalla de una vez.
CREATE OR REPLACE FUNCTION fn_islr_tarifa_modelo() RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT jsonb_agg(jsonb_build_object(
        'codigo', m.codigo,
        'nombre', CASE WHEN m.codigo = 'T1' THEN 'Tarifa 1 · personas naturales residentes' ELSE 'Tarifa 2 · sociedades de residentes' END,
        'nota', CASE WHEN m.codigo = 'T1' THEN '8 tramos del 6 % al 34 % (art. 53 de la Ley de ISLR, reforma 2020). Úsala si el negocio es de una persona natural (RIF V o E).'
                     ELSE '15 % hasta 2.000 UT, 22 % hasta 3.000 UT y 34 % después (art. 53 de la Ley de ISLR). Úsala si el negocio es una compañía (RIF J o G).' END,
        'tramos', (SELECT jsonb_agg(jsonb_build_object('desde_ut', t.desde_ut, 'hasta_ut', t.hasta_ut, 'pct', t.pct, 'sustraendo_ut', t.sustraendo_ut) ORDER BY t.desde_ut)
                   FROM islr_tarifa_modelo t WHERE t.codigo = m.codigo)
    ) ORDER BY m.codigo)
    FROM (SELECT DISTINCT codigo FROM islr_tarifa_modelo) m;
$$;

-- Aplica una plantilla al ejercicio: reemplaza los tramos de islr_tarifa_tramos con la
-- validación completa de fn_islr_tramos_guardar. La tarifa del ejercicio sigue editable.
CREATE OR REPLACE FUNCTION fn_islr_tarifa_modelo_aplicar(p_ejercicio int, p_codigo text) RETURNS int LANGUAGE plpgsql AS $$
DECLARE v_json jsonb; v_tramos jsonb; v_n int;
BEGIN
    IF p_ejercicio IS NULL OR p_ejercicio NOT BETWEEN 2000 AND 2100 THEN RAISE EXCEPTION 'El ejercicio no es válido' USING ERRCODE = 'TV006'; END IF;
    IF p_codigo IS NULL OR p_codigo NOT IN ('T1', 'T2') THEN RAISE EXCEPTION 'La tarifa debe ser T1 (personas naturales) o T2 (sociedades)' USING ERRCODE = 'TV006'; END IF;
    IF EXISTS (SELECT 1 FROM islr_declaracion WHERE ejercicio = p_ejercicio AND contabilizada) THEN
        RAISE EXCEPTION 'El ejercicio % ya está liquidado en la contabilidad: descontabilízalo antes de cambiar la tarifa', p_ejercicio USING ERRCODE = 'TV006';
    END IF;
    SELECT e->'tramos' INTO v_tramos FROM jsonb_array_elements(fn_islr_tarifa_modelo()) e WHERE (e->>'codigo') = p_codigo;
    IF v_tramos IS NULL OR jsonb_typeof(v_tramos) <> 'array' OR jsonb_array_length(v_tramos) = 0 THEN
        RAISE EXCEPTION 'La plantilla % no tiene tramos: reinstala 40_islr_tarifas_modelo.sql', p_codigo USING ERRCODE = 'TV007';
    END IF;
    v_n := fn_islr_tramos_guardar(p_ejercicio, v_tramos);
    RETURN v_n;
END $$;

-- ── Revisión antes de liquidar (la muestra la pantalla; no bloquea) ──────────
-- Cada fila: {clave, estado ('OK'|'AVISO'), mensaje}. Es información para decidir:
-- los bloqueos duros (sin tramos, sin UT, ya liquidado) siguen en fn_islr_contabilizar.
CREATE OR REPLACE FUNCTION fn_islr_verificaciones(p_ejercicio int) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE
    b jsonb; v_ut numeric; v_renta_ut numeric; v_n int; v_sin_comp int; v_pend int; v_hoy date := fn_hoy();
    v_vence date; v_anio int; v_revisar jsonb := '[]'::jsonb;
BEGIN
    IF p_ejercicio IS NULL OR p_ejercicio NOT BETWEEN 2000 AND 2100 THEN RAISE EXCEPTION 'El ejercicio no es válido' USING ERRCODE = 'TV006'; END IF;
    b := fn_islr_borrador(p_ejercicio);
    v_ut := b->>'ut';
    v_renta_ut := b->>'renta_neta_ut';

    -- 1 · Unidad tributaria al cierre
    v_revisar := v_revisar || jsonb_build_object('clave', 'ut', 'estado', CASE WHEN v_ut IS NOT NULL THEN 'OK' ELSE 'AVISO' END,
        'mensaje', CASE WHEN v_ut IS NOT NULL THEN 'Unidad tributaria del cierre: Bs ' || to_char(v_ut, 'FM999G990D00') || '.'
                        ELSE 'Falta la unidad tributaria vigente al cierre (Retenciones de ISLR → Parámetros): sin ella no hay impuesto.' END);

    -- 2 · Datos del contador del ejercicio
    v_revisar := v_revisar || jsonb_build_object('clave', 'datos_contador', 'estado', CASE WHEN (b->>'configurado')::boolean THEN 'OK' ELSE 'AVISO' END,
        'mensaje', CASE WHEN (b->>'configurado')::boolean THEN 'Las cifras del contador están guardadas (gastos, deducciones, ajuste y rebajas).'
                        ELSE 'Sin gastos, deducciones ni ajuste por inflación: haz clic en «Datos del contador» y complétalos con tu contador.' END);

    -- 3 · Tarifa cargada y que cubra la renta
    SELECT count(*) INTO v_n FROM islr_tarifa_tramos WHERE ejercicio = p_ejercicio;
    IF v_n = 0 THEN
        v_revisar := v_revisar || jsonb_build_object('clave', 'tarifa', 'estado', 'AVISO',
            'mensaje', 'Sin tarifa no hay impuesto: en «Tarifa» usa una plantilla oficial (Tarifa 1 persona natural, Tarifa 2 sociedad) o cárgala según tu contador.');
    ELSE
        IF (b->>'renta_neta')::numeric <= 0 THEN
            v_revisar := v_revisar || jsonb_build_object('clave', 'tarifa', 'estado', 'OK',
                'mensaje', 'Tarifa cargada (' || v_n || ' tramos). La renta del ejercicio es una pérdida o cero: impuesto cero, se declara igualmente.');
        ELSIF v_renta_ut IS NULL THEN
            v_revisar := v_revisar || jsonb_build_object('clave', 'tarifa', 'estado', 'AVISO',
                'mensaje', 'Hay tarifa pero falta la UT al cierre: la renta no se puede convertir a unidades tributarias.');
        ELSE
            SELECT count(*) INTO v_n FROM islr_tarifa_tramos WHERE ejercicio = p_ejercicio AND desde_ut < v_renta_ut AND (hasta_ut IS NULL OR v_renta_ut <= hasta_ut);
            v_revisar := v_revisar || jsonb_build_object('clave', 'tarifa', 'estado', CASE WHEN v_n > 0 THEN 'OK' ELSE 'AVISO' END,
                'mensaje', CASE WHEN v_n > 0 THEN 'Tarifa cargada y cubre la renta del ejercicio (' || to_char(v_renta_ut, 'FM999G990D00') || ' UT).'
                                ELSE 'Ningún tramo cubre la renta (' || to_char(v_renta_ut, 'FM999G990D00') || ' UT): revisa la tarifa con tu contador.' END);
        END IF;
    END IF;

    -- 4 · Retenciones sufridas sin número de comprobante
    SELECT count(*) INTO v_sin_comp FROM islr_retenciones_sufridas WHERE estado = 'ACTIVO' AND comprobante IS NULL
       AND fecha BETWEEN (b->>'desde')::date AND (b->>'hasta')::date;
    v_revisar := v_revisar || jsonb_build_object('clave', 'comprobantes', 'estado', CASE WHEN v_sin_comp = 0 THEN 'OK' ELSE 'AVISO' END,
        'mensaje', CASE WHEN v_sin_comp = 0 THEN 'Todas las retenciones que te practicaron tienen su número de comprobante.'
                        ELSE v_sin_comp::text || ' retención(es) recibida(s) sin número de comprobante: sin él el SENIAT no las acepta al acreditar.' END);

    -- 5 · Ejercicios anteriores sin liquidar
    SELECT count(*) INTO v_pend FROM islr_declaracion WHERE ejercicio < p_ejercicio AND NOT contabilizada;
    v_revisar := v_revisar || jsonb_build_object('clave', 'ejercicios_pendientes', 'estado', CASE WHEN v_pend = 0 THEN 'OK' ELSE 'AVISO' END,
        'mensaje', CASE WHEN v_pend = 0 THEN 'No hay ejercicios anteriores pendientes de liquidar.'
                        ELSE v_pend::text || ' ejercicio(s) anterior(es) sin liquidar en la contabilidad: liquida primero el más antiguo.' END);

    -- 6 · Ventana de presentación de la definitiva (informativa, nunca bloquea)
    v_anio := p_ejercicio + 1;
    v_vence := make_date(v_anio, 3, 31);
    IF (b->>'hasta')::date >= v_hoy THEN
        v_revisar := v_revisar || jsonb_build_object('clave', 'plazo', 'estado', 'AVISO',
            'mensaje', 'El ejercicio aún está en curso: la definitiva se presenta en los primeros 3 meses del año siguiente al cierre (estimada: junio).');
    ELSIF v_hoy BETWEEN make_date(v_anio, 1, 1) AND v_vence THEN
        v_revisar := v_revisar || jsonb_build_object('clave', 'plazo', 'estado', 'OK',
            'mensaje', 'Estamos en la ventana de la definitiva (enero a marzo del ' || v_anio || '): presenta en el portal antes del ' || to_char(v_vence, 'DD/MM/YYYY') || '.');
    ELSE
        v_revisar := v_revisar || jsonb_build_object('clave', 'plazo', 'estado', 'AVISO',
            'mensaje', CASE WHEN v_hoy < make_date(v_anio, 1, 1)
                THEN 'La ventana de la definitiva abre en enero del ' || v_anio || ' y cierra el 31/03/' || v_anio || '.'
                ELSE 'La ventana regular (enero a marzo del ' || v_anio || ') ya venció: si aún no presentaste, hazlo cuanto antes por el portal (puede haber multa).' END);
    END IF;

    RETURN v_revisar;
END $$;
COMMENT ON FUNCTION fn_islr_verificaciones(int) IS 'Lista de revisión de la declaración anual (UT, datos del contador, tarifa, comprobantes, ejercicios pendientes y ventana de presentación). Informativa: los bloqueos duros viven en fn_islr_contabilizar.';
