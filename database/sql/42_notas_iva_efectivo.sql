-- =============================================================================
--  42 · IVA efectivo de las notas de ajuste
-- =============================================================================
--  sp_registrar_nota_ajuste calculaba SIEMPRE el IVA con ventas.iva_pct. En una
--  venta 100 % exenta o exonerada (base_ves = 0) eso le sumaba a la nota un IVA
--  que la venta nunca causó, y la nota salía descuadrada frente a la factura.
--
--  Ahora el emisor puede indicar la alícuota efectiva de la venta:
--    · venta con base gravada  → la alícuota general (ventas.iva_pct)
--    · venta sin base gravada  → 0
--  Si no se indica (p_iva_pct NULL) se conserva el comportamiento anterior.
--
--  El parámetro se agrega al final y con valor por defecto, así que las llamadas
--  de 8 o 10 argumentos que ya existen siguen funcionando.
-- =============================================================================

SET search_path = tienda, public;

DROP PROCEDURE IF EXISTS sp_registrar_nota_ajuste(char, bigint, text, numeric, text, bigint, text, date, boolean, jsonb);

CREATE OR REPLACE PROCEDURE sp_registrar_nota_ajuste(
    p_tipo char(1), p_venta_id bigint, p_numero text, p_base_ves numeric, p_motivo text, p_usuario_id bigint,
    p_numero_control text DEFAULT NULL, p_fecha date DEFAULT NULL,
    p_devuelve_inventario boolean DEFAULT false, p_productos jsonb DEFAULT NULL,
    p_iva_pct numeric DEFAULT NULL)
LANGUAGE plpgsql AS $$
DECLARE
    v ventas;
    iva numeric;
    v_tasa numeric;
    v_nota_id bigint;
    r record;
    f record;
    v_devuelve boolean := COALESCE(p_devuelve_inventario, false);
BEGIN
    IF p_tipo NOT IN ('C','D') THEN RAISE EXCEPTION 'Tipo de nota inválido: use C (crédito) o D (débito)' USING ERRCODE = 'TV006'; END IF;
    IF p_base_ves IS NULL OR p_base_ves <= 0 THEN RAISE EXCEPTION 'La base de la nota debe ser mayor que cero' USING ERRCODE = 'TV006'; END IF;
    IF p_motivo IS NULL OR length(trim(p_motivo)) < 3 THEN RAISE EXCEPTION 'Indique el motivo de la nota' USING ERRCODE = 'TV006'; END IF;
    IF v_devuelve AND p_tipo <> 'C' THEN RAISE EXCEPTION 'Solo una nota de crédito puede devolver mercancía al inventario' USING ERRCODE = 'TV006'; END IF;
    IF v_devuelve AND (p_productos IS NULL OR jsonb_typeof(p_productos) <> 'array' OR jsonb_array_length(p_productos) = 0) THEN
        RAISE EXCEPTION 'Indique qué productos devuelve el cliente' USING ERRCODE = 'TV006';
    END IF;
    SELECT * INTO v FROM ventas WHERE id = p_venta_id FOR UPDATE;      -- serializa las notas de una misma venta: dos no devuelven lo mismo
    IF v.id IS NULL OR v.estado <> 'COMPLETADA' THEN RAISE EXCEPTION 'La venta % no existe o está anulada', p_venta_id USING ERRCODE = 'TV006'; END IF;

    -- Alícuota efectiva: 0 en ventas sin base gravada (todo exento/exonerado).
    v_tasa := COALESCE(p_iva_pct, v.iva_pct);
    IF v_tasa < 0 THEN v_tasa := 0; END IF;
    iva := ROUND(p_base_ves * v_tasa / 100, 2);

    -- Valida ANTES de insertar nada.
    IF v_devuelve THEN
        FOR r IN SELECT x.producto_id, sum(x.cantidad) AS cantidad
                   FROM jsonb_to_recordset(p_productos) AS x(producto_id bigint, cantidad numeric) GROUP BY x.producto_id ORDER BY x.producto_id LOOP
            IF r.producto_id IS NULL OR r.cantidad IS NULL OR r.cantidad <= 0 THEN RAISE EXCEPTION 'Cada producto devuelto necesita una cantidad mayor que cero' USING ERRCODE = 'TV006'; END IF;
            SELECT * INTO f FROM fn_devolvibles_venta(p_venta_id) WHERE producto_id = r.producto_id;
            IF f.producto_id IS NULL THEN RAISE EXCEPTION 'El producto % no forma parte de la venta %', r.producto_id, v.numero USING ERRCODE = 'TV006'; END IF;
            IF r.cantidad > f.disponible THEN
                RAISE EXCEPTION 'No puedes devolver % unidades de "%" (%): se vendieron %, ya se devolvieron % y quedan % por devolver',
                    r.cantidad, f.nombre, f.sku, f.vendida, f.devuelta, f.disponible USING ERRCODE = 'TV006';
            END IF;
        END LOOP;
    END IF;

    INSERT INTO notas_ajuste (tipo, venta_id, numero, numero_control, fecha, iva_pct, base_ves, iva_ves, total_ves, motivo, usuario_id, devuelve_inventario)
    VALUES (p_tipo, p_venta_id, trim(p_numero), p_numero_control, COALESCE(p_fecha, fn_hoy()), v_tasa, p_base_ves, iva, p_base_ves + iva, trim(p_motivo), p_usuario_id, v_devuelve)
    RETURNING id INTO v_nota_id;                                         -- aquí el trigger del 10 genera el asiento fiscal (y falla si el período está cerrado)

    IF v_devuelve THEN
        PERFORM set_config('tienda.usuario_id', COALESCE(p_usuario_id::text, ''), true);
        FOR r IN SELECT x.producto_id, sum(x.cantidad) AS cantidad
                   FROM jsonb_to_recordset(p_productos) AS x(producto_id bigint, cantidad numeric) GROUP BY x.producto_id ORDER BY x.producto_id LOOP
            INSERT INTO movimientos_inventario (producto_id, tipo, cantidad, referencia_tipo, referencia_id, motivo, usuario_id)
            VALUES (r.producto_id, 'DEVOLUCION_CLIENTE', r.cantidad, 'NOTA_CREDITO', v_nota_id, 'Devolución del cliente · ' || trim(p_numero) || ' · ' || trim(p_motivo), p_usuario_id);
            INSERT INTO notas_ajuste_detalle (nota_id, producto_id, cantidad) VALUES (v_nota_id, r.producto_id, r.cantidad);   -- dispara el asiento de costo
        END LOOP;
    END IF;
END $$;

COMMENT ON PROCEDURE sp_registrar_nota_ajuste(char, bigint, text, numeric, text, bigint, text, date, boolean, jsonb, numeric) IS
    'Registra una nota de crédito (C) o débito (D). p_iva_pct es la alícuota efectiva de la venta (0 si no hay base gravada); NULL usa ventas.iva_pct.';