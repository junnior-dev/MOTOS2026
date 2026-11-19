# -*- coding: utf-8 -*-
"""t20 · VALIDACIÓN FISCAL FUERTE con datos simulados (fakerphp/faker).

Genera un negocio completo simulado (clientes, proveedores, productos, 160 ventas,
45 compras, retenciones de IVA e ISLR) y valida CADA resultado contra las reglas
del SENIAT verificadas por normativa vigente:

  IVA general 16 %            · base × 0,16 redondeado a 2 decimales por línea
  Exento / exonerado          · IVA 0, va aparte en el libro
  IGTF divisas 3 %            · 3 % del pago en divisa (base CON_IVA) — Decreto 4.978
  Retención IVA 75 % / 100 %  · Providencia SNAT/2025/000054 (Gaceta 43.171)
  Retención ISLR Decreto 1808 · natural: base × % − sustraendo (UT × % × 83,3334)
                                jurídica: base × %; honorarios 3/5, servicios 1/2…
  UT vigente                  · Bs 43,00 (SNAT/2025/000048, Gaceta 43.140, 02/06/2025)

Y las invariantes contables: partida doble por asiento y global, libro de ventas
vs asientos, libro de compras y crédito fiscal, kardex y stock, correlativo.
"""
import json
import os
import subprocess
import sys
import time
from pathlib import Path

import psycopg2

RAIZ = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(RAIZ / 'tests' / 'e2e'))
import e2e_lib as L  # noqa: E402

PGHOST = os.environ.get('PGHOST_PRUEBAS', '/tmp')
PGPORT = int(os.environ.get('PGPORT_PRUEBAS', '5433'))
CLON = 'tienda_faker'
PHP = os.environ.get('PHP') or 'php'
UT_OFICIAL = 43.00          # SNAT/2025/000048 (G.O. 43.140, 02/06/2025) — vigente en 2026
IVA = 16.0                  # alícuota general vigente desde 2015

CONTEO = [0, 0]
def ok(cond, msg, detalle=''):
    CONTEO[0 if cond else 1] += 1
    print(f'  {"OK  " if cond else "FAIL"} {msg}' + (f'  [{detalle}]' if detalle and not cond else ''))
    return bool(cond)


def r2(x):
    return round(float(x) + 1e-9, 2)


def conectar():
    con = psycopg2.connect(host=PGHOST, port=PGPORT, dbname=CLON, user='postgres',
                           options='-c search_path=tienda,public -c TimeZone=America/Caracas')
    con.autocommit = False
    return con


def crear_clon():
    con = psycopg2.connect(host=PGHOST, port=PGPORT, dbname='postgres', user='postgres')
    con.autocommit = True
    cur = con.cursor()
    for _ in range(6):
        try:
            cur.execute('SELECT 1 FROM pg_database WHERE datname = %s', (CLON,))
            if cur.fetchone():
                cur.execute(f'DROP DATABASE {CLON}')
            cur.execute(f'CREATE DATABASE {CLON} TEMPLATE tienda')
            cur.close()
            con.close()
            return True
        except psycopg2.Error:
            time.sleep(2)
    con.close()
    return False


def q(cur, sql, args=()):
    cur.execute(sql, args)
    return cur.fetchall()


def main():
    print('== t20 · Validación fiscal con datos simulados (faker) ==', flush=True)
    L.empezar('c0 · Clon dedicado + datos simulados')
    L.ok(crear_clon(), f'clon {CLON} creado desde la plantilla «tienda»')
    L.DSN['dbname'] = CLON
    L.conectar()
    cur = L.conn.cursor()

    datos = json.loads(subprocess.run(
        [PHP, str(RAIZ / 'scripts' / 'carga' / 'generador_fiscal.php')],
        capture_output=True, text=True, cwd=str(RAIZ)).stdout.strip().split('\n')[-1] and
        open('/tmp/datos_fiscales.json', encoding='utf-8').read())
    uid = L.admin()
    L.ok(True, f"faker generó {len(datos['ventas'])} ventas · {len(datos['compras'])} compras · "
               f"{len(datos['islr'])} retenciones ISLR · {len(datos['retenciones_iva'])} retenciones de IVA")

    # unidad tributaria OFICIAL vigente + contabilidad automática para auditar asientos
    cur.execute('SELECT tienda.fn_islr_guardar_ut(%s, %s, %s, %s)',
                ('2025-06-02', UT_OFICIAL, 'UT oficial SNAT/2025/000048 (G.O. 43.140)', uid))
    cur.execute('UPDATE tienda.contab_config SET automatica = true')
    L.commit()
    L.ok(True, f'UT cargada = Bs {UT_OFICIAL} · contabilidad automática activada')

    # turno de caja PROPIO para el vendedor simulado: la plantilla limpia exige turno
    # (caja_config.exige_turno = true) y esta suite no debe depender de residuos de otras
    # suites en la plantilla (el patrón del bug #6: los tests se apoyan en basura ajena).
    cur.execute('SELECT tienda.fn_apertura_activa(%s)', (uid,))
    if cur.fetchone()[0] is None:
        cur.execute('SELECT tienda.fn_abrir_caja(%s, 500, 18500)', (uid,))
        L.commit()
        L.ok(True, 'turno de caja abierto para el vendedor simulado (la plantilla exige turno)')

    # ── c1 · catálogo simulado ────────────────────────────────────────────────
    L.empezar('c1 · Catálogo simulado (clientes, proveedores, productos faker)')
    ids_prod = {}
    for p in datos['productos']:
        precio = r2(p['costo_usd'] * (1 + p['margen_pct'] / 100))
        cur.execute("""INSERT INTO tienda.productos
                       (sku, nombre, unidad, costo_usd, margen_pct, precio_venta_usd, exento_iva, exonerado_iva,
                        stock_actual, stock_minimo, activo, codigo_barras)
                       VALUES (%s, %s, 'UND', %s, %s, %s, %s, %s, 0, 2, true, %s) RETURNING id""",
                    (p['sku'], p['nombre'], p['costo_usd'], p['margen_pct'], precio,
                     p['exento_iva'], p['exonerado_iva'], p['barcode']))
        ids_prod[p['sku']] = cur.fetchone()[0]
    # los productos del generador pueden repetir nombre de la semilla: usa sku propio
    L.commit()
    L.ok(len(ids_prod) == len(datos['productos']), f'{len(ids_prod)} productos faker creados con su EAN-13')
    for p in datos['productos']:
        pid = ids_prod[p['sku']]
        cur.execute('CALL tienda.sp_ajustar_stock(%s, %s, %s, %s)', (pid, p['stock'], 'Inventario inicial simulado', uid))
    L.commit()

    ids_cli = {}
    for c in datos['clientes']:
        cur.execute("SELECT id FROM tienda.clientes WHERE documento = %s AND tipo_documento = %s", (c['documento'], c['tipo']))
        r = cur.fetchone()
        if r:
            ids_cli[c['documento']] = r[0]
            continue
        cur.execute("""INSERT INTO tienda.clientes (tipo_documento, documento, nombre, telefono, correo, activo)
                       VALUES (%s, %s, %s, %s, %s, true) RETURNING id""",
                    (c['tipo'], c['documento'], c['nombre'], c['telefono'], c['correo']))
        ids_cli[c['documento']] = cur.fetchone()[0]
    ids_prov = {}
    for pv in datos['proveedores']:
        cur.execute("""INSERT INTO tienda.proveedores (razon_social, rif, telefono, correo, activo)
                       VALUES (%s, %s, %s, %s, true) RETURNING id""",
                    (pv['razon_social'], pv['rif'], pv['telefono'], pv['correo']))
        ids_prov[pv['razon_social']] = cur.fetchone()[0]
    L.commit()
    L.ok(len(ids_cli) == len(datos['clientes']) and len(ids_prov) == len(datos['proveedores']),
         f'{len(ids_cli)} clientes y {len(ids_prov)} proveedores simulados registrados')

    stock_inicial = dict(q(cur, 'SELECT id, stock_actual FROM tienda.productos'))
    # la plantilla puede traer ventas/movimientos/asientos de t16/t18: los validadores
    # solo miran lo que ESTA suite crea (ids posteriores al snapshot)
    snap_venta = int(q(cur, 'SELECT COALESCE(max(id), 0) FROM tienda.ventas')[0][0])
    snap_mov = int(q(cur, 'SELECT COALESCE(max(id), 0) FROM tienda.movimientos_inventario')[0][0])
    snap_compra = int(q(cur, 'SELECT COALESCE(max(id), 0) FROM tienda.compras')[0][0])
    L.ok(True, f'snapshot: ventas hasta {snap_venta}, movimientos hasta {snap_mov}, compras hasta {snap_compra}')

    # ── c2 · compras simuladas ────────────────────────────────────────────────
    L.empezar('c2 · Compras simuladas: registro, crédito fiscal y retenciones de IVA')
    ids_compra = [None] * len(datos['compras'])
    fallas_compra = []
    for i, comp in enumerate(datos['compras']):
        try:
            items = json.dumps([{'producto_id': ids_prod[datos['productos'][it['producto_idx']]['sku']],
                                 'cantidad': it['cantidad'], 'costo_unitario': it['costo_unitario'], 'exento': it['exento']}
                                for it in comp['items']])
            cur.execute('SELECT tienda.fn_registrar_compra(%s, %s, fn_hoy(), %s, %s, %s, %s, %s, %s)',
                        (ids_prov[datos['proveedores'][comp['proveedor_idx']]['razon_social']],
                         comp['numero_factura'] + f'-{i}', 'USD', items, uid,
                         comp['numero_control'] + f'-{i}', comp['aplica_credito_fiscal'], None))
            ids_compra[i] = cur.fetchone()[0]
            L.commit()
        except psycopg2.Error as e:
            L.rollback()
            fallas_compra.append((i, (e.diag.message_primary or str(e))[:100]))
    n_ok = len([x for x in ids_compra if x])
    L.ok(not fallas_compra, f'las {n_ok} compras se registraron sin error',
         str(fallas_compra[:2]))

    registradas_iva = 0
    for ret in datos['retenciones_iva']:
        idx = ret['compra_idx']
        if idx >= len(ids_compra) or ids_compra[idx] is None:
            continue
        cid = ids_compra[idx]
        # el IVA de la retención va en Bs (el SP lo valida contra v_libro_compras)
        fila = q(cur, 'SELECT iva_ves FROM tienda.v_libro_compras WHERE compra_id = %s', (cid,))
        iva = float(fila[0][0]) if fila and fila[0][0] else 0.0
        if iva <= 0:
            continue
        comprobante = time.strftime('%Y%m') + f'{random_ret_numero(idx)}'
        try:
            cur.execute('CALL tienda.sp_registrar_retencion_iva(%s, %s, %s, fn_hoy(), %s, %s, %s, %s)',
                        ('COMPRA', cid, comprobante, ret['porcentaje'], uid, r2(iva * ret['porcentaje'] / 100), None))
            L.commit()
            registradas_iva += 1
        except psycopg2.Error as e:
            L.rollback()
            L.ok(False, f'retención IVA compra #{cid} falló', (e.diag.message_primary or '')[:110])
    n_ret = q(cur, "SELECT count(*) FROM tienda.retenciones_iva WHERE sentido = 'COMPRA'")[0][0]
    L.ok(int(n_ret) == registradas_iva,
         f'{n_ret} retenciones de IVA registradas sobre compras con crédito fiscal (en Bs)')

    # VALIDACIÓN V6 · retención IVA = round(iva × %, 2) — SNAT/2025/000054
    filas = q(cur, """SELECT r.porcentaje, r.iva_documento_ves, r.iva_retenido_ves, c.id
                      FROM tienda.retenciones_iva r JOIN tienda.compras c ON c.id = r.compra_id
                      WHERE r.sentido = 'COMPRA' AND r.estado = 'ACTIVA'""")
    malas = [(int(c), float(iv), float(ir), float(pct)) for pct, iv, ir, c in filas
             if abs(float(ir) - r2(float(iv) * float(pct) / 100)) > 0.005]
    L.ok(not malas, f'V6 · las {len(filas)} retenciones de IVA = IVA × 75/100 % al céntimo (SNAT/2025/000054)',
         str(malas[:2]))

    # ── c3 · ventas simuladas ─────────────────────────────────────────────────
    L.empezar('c3 · Ventas simuladas: POS con IVA, IGTF, descuentos, mixto y crédito')
    ids_venta = []
    ventas_spec = []
    fallas_venta = []
    tasa_hoy = float(q(cur, "SELECT tasa FROM tienda.tasas_cambio WHERE moneda_origen='USD' AND moneda_destino='VES' ORDER BY fecha_valor DESC LIMIT 1")[0][0])
    for i, v in enumerate(datos['ventas']):
        try:
            items = json.dumps([{'producto_id': ids_prod[datos['productos'][it['producto_idx']]['sku']],
                                 'cantidad': it['cantidad'], 'descuento_pct': it['descuento_pct']} for it in v['items']])
            cur.execute('SELECT * FROM tienda.fn_cotizar_venta(%s)', (items,))
            cot = cur.fetchone()
            total = float(cot[6])
            pagos, es_credito = [], False
            modo = v['pago']['modo']
            if modo == 'bs':
                pagos = [{'metodo_pago_id': 4, 'monto': r2(total * tasa_hoy), 'referencia': f'FK-V{i}'}]
            elif modo == 'divisa':
                pagos = [{'metodo_pago_id': 2, 'monto': r2(total), 'referencia': f'FK-V{i}'}]
            elif modo == 'mixto':
                usd_part = r2(total * v['pago']['parte_usd'])
                pagos = [{'metodo_pago_id': 2, 'monto': usd_part, 'referencia': f'FK-V{i}a'},
                         {'metodo_pago_id': 3, 'monto': r2((total - usd_part) * tasa_hoy), 'referencia': f'FK-V{i}b'}]
            else:
                es_credito = True
                if v['cliente_idx'] is None:
                    v['cliente_idx'] = 0
            cli = ids_cli[datos['clientes'][v['cliente_idx']]['documento']] if v['cliente_idx'] is not None else None
            cur.execute('SELECT tienda.fn_registrar_venta(%s, %s, %s, %s, %s, %s, %s, %s)',
                        (uid, items, json.dumps(pagos), cli, v['observaciones'], 'PRESENCIAL', es_credito,
                         v['pago'].get('dias', 30)))
            vid = cur.fetchone()[0]
            ids_venta.append(vid)
            ventas_spec.append({'id': vid, 'items': v['items'], 'pago': v['pago'], 'anular': v['anular'],
                                'motivo': v['motivo_anulacion'], 'total': total,
                                'cliente': cli, 'productos': [ids_prod[datos['productos'][it['producto_idx']]['sku']] for it in v['items']]})
            L.commit()
        except psycopg2.Error as e:
            L.rollback()
            msg = (e.diag.message_primary or str(e))
            if 'TV003' in msg or 'stock' in msg.lower():
                # reponer el stock de los productos de la venta y reintentar una vez
                L.rollback()
                try:
                    for pid in set(ids_prod[datos['productos'][it['producto_idx']]['sku']] for it in v['items']):
                        cur.execute('CALL tienda.sp_ajustar_stock(%s, 50, %s, %s)', (pid, 'Reposición simulada', uid))
                    L.commit()
                    i = i - 1  # reintentar esta venta
                    continue
                except psycopg2.Error:
                    L.rollback()
            fallas_venta.append((i, msg[:100]))
    L.ok(not fallas_venta, f'las {len(ids_venta)} ventas simuladas se registraron sin error',
         str(fallas_venta[:3]))

    # anular una muestra (las anuladas van al libro con estado y revierten IGTF/stock)
    anuladas = []
    for spec in ventas_spec:
        if spec['anular'] and len(ids_venta) - len(anuladas) > 30:
            try:
                cur.execute('CALL tienda.sp_anular_venta(%s, %s, %s)', (spec['id'], uid, spec['motivo']))
                L.commit()
                anuladas.append(spec['id'])
                spec['anulada'] = True
            except psycopg2.Error as e:
                L.rollback()
                spec['anulada'] = False
    L.ok(len(anuladas) >= 3, f'{len(anuladas)} ventas anuladas (inmutabilidad + reversión de stock/IGTF)')

    # ── V1 · IVA por línea contra la regla oficial ────────────────────────────
    L.empezar('c4 · Validación fiscal línea a línea y libro de ventas')
    filas = q(cur, """SELECT d.venta_id, d.cantidad, d.precio_unitario_usd, d.descuento_pct, d.exento,
                             d.base_usd, d.iva_usd, d.total_usd, d.base_ves, d.iva_ves,
                             v.iva_pct, v.precios_incluyen_iva, v.tasa_cambio
                      FROM tienda.detalle_venta d JOIN tienda.ventas v ON v.id = d.venta_id""")
    malas_iva, malas_bs = [], []
    for (vid, cant, precio, desc, exento, base, iva, total, base_ves, iva_ves, iva_pct, incl, tasa) in filas:
        bruto = float(cant) * float(precio) * (1 - float(desc or 0) / 100)
        if exento:
            e_base, e_iva, e_total = r2(bruto), 0.0, r2(bruto)
        elif incl:
            e_total = r2(bruto)
            e_base = r2(e_total / (1 + float(iva_pct) / 100))
            e_iva = r2(e_total - e_base)
        else:
            e_base = r2(bruto)
            e_iva = r2(e_base * float(iva_pct) / 100)
            e_total = r2(e_base + e_iva)
        if abs(float(base) - e_base) > 0.005 or abs(float(iva) - e_iva) > 0.005 or abs(float(total) - e_total) > 0.005:
            malas_iva.append((int(vid), float(base), e_base, float(iva), e_iva))
        if not exento and abs(float(iva_ves) - r2(r2(e_base * float(tasa)) * float(iva_pct) / 100)) > 0.005:
            malas_bs.append((int(vid), float(iva_ves), r2(r2(e_base * float(tasa)) * float(iva_pct) / 100)))
    L.ok(not malas_iva, f'V1 · IVA por línea de {len(filas)} renglones: base/IVA/total exactos con alícuota congelada {IVA:g} %',
         str(malas_iva[:2]))
    L.ok(not malas_bs, f'V1b · IVA en Bs por línea: base_ves × {IVA:g} % con la tasa de cada venta', str(malas_bs[:2]))

    # V2 · cabecera = suma de líneas
    malas = q(cur, """SELECT v.id FROM tienda.ventas v
                      WHERE v.estado = 'COMPLETADA' AND ABS(v.base_usd + v.exento_usd + v.iva_usd - v.total_usd) > 0.01""")
    L.ok(not malas, f'V2 · cabecera = suma de líneas en las {len(ids_venta)} ventas (base + exento + IVA = total)')

    # V3 · IGTF de divisas al 3 %
    igtf_esperado = {}
    cur.execute("""SELECT venta_id, metodo_pago_id, monto_usd, monto_ves FROM tienda.pagos_venta""")
    for vid, mid, musd, mves in cur.fetchall():
        cur.execute('SELECT es_divisa FROM tienda.metodos_pago WHERE id = %s', (mid,))
        if cur.fetchone()[0]:
            igtf_esperado[vid] = igtf_esperado.get(vid, 0) + r2(float(musd or 0) * 3 / 100)
    filas = q(cur, 'SELECT id, igtf_usd FROM tienda.ventas WHERE estado = %s', ('COMPLETADA',))
    malas = [(int(vid), float(g), igtf_esperado.get(vid, 0)) for vid, g in filas
             if abs(float(g) - igtf_esperado.get(vid, 0)) > 0.01]
    L.ok(not malas, f'V3 · IGTF 3 % sobre pagos en divisa (base CON_IVA) exacto en {len(filas)} ventas',
         str(malas[:2]))
    malas = q(cur, """SELECT v.id FROM tienda.ventas v WHERE v.estado = 'COMPLETADA' AND v.igtf_usd > 0
                      AND ABS(v.total_cobrar_usd - (v.total_usd + v.igtf_usd)) > 0.01""")
    L.ok(not malas, 'V3b · el total a cobrar incluye el IGTF (el cliente paga total + IGTF)')

    # V4 · libro de ventas (v_libro_ventas) vs recomputación
    libro = q(cur, """SELECT COALESCE(sum(base_imponible_ves),0), COALESCE(sum(exento_ves + exonerado_ves),0),
                             COALESCE(sum(iva_ves),0), COALESCE(sum(total_ves),0), count(*)
                      FROM tienda.v_libro_ventas WHERE estado = 'COMPLETADA'""")[0]
    esperado = q(cur, """SELECT COALESCE(sum(base_ves),0), COALESCE(sum(exento_ves),0),
                                COALESCE(sum(iva_ves),0), COALESCE(sum(total_ves),0), count(*)
                         FROM tienda.ventas WHERE estado = 'COMPLETADA'""")[0]
    cuadra = all(abs(float(a) - float(b)) < 0.5 for a, b in zip(libro[:4], esperado[:4])) and libro[4] == esperado[4]
    L.ok(cuadra, f'V4 · v_libro_ventas cuadra con las ventas ({libro[4]} facturas COMPLETADAS; '
                 f'IVA Bs {float(libro[2]):,.2f})', f'libro={libro} esperado={esperado}')
    anuladas_libro = q(cur, "SELECT count(*) FROM tienda.v_libro_ventas WHERE estado = 'ANULADA'")[0][0]
    L.ok(int(anuladas_libro) == len(anuladas),
         f'V4b · las {len(anuladas)} anuladas aparecen en el libro con estado ANULADA (no se suman)')

    # las anulaciones NO generan nota de crédito (son FACTURA con estado ANULADA);
    # las NOTA_CREDITO del libro nacen de devoluciones (notas_ajuste), que aquí no hay
    notas = q(cur, "SELECT count(*) FROM tienda.v_libro_ventas WHERE tipo_documento = 'NOTA_CREDITO'")[0][0]
    L.ok(int(notas) == 0 and int(anuladas_libro) == len(anuladas),
         'V4c · anulaciones = FACTURA en estado ANULADA (no nota de crédito); sin devoluciones no hay NC')

    # ── c5 · libro de compras y crédito fiscal ────────────────────────────────
    L.empezar('c5 · Libro de compras: crédito fiscal e IVA al costo')
    filas = q(cur, """SELECT compra_id, base_imponible_ves, exento_ves, iva_ves, credito_fiscal_ves, total_ves, estado
                      FROM tienda.v_libro_compras WHERE estado = 'REGISTRADA'""")
    malas_cr, malas_iva_c = [], []
    for (cid, base, exento, iva, cr, total, estado) in filas:
        aplica = bool(q(cur, 'SELECT aplica_credito_fiscal FROM tienda.compras WHERE id = %s', (cid,))[0][0])
        e_cr = float(iva) if aplica else 0.0
        if abs(float(cr) - e_cr) > 0.005:
            malas_cr.append((int(cid), float(cr), e_cr))
        if abs(float(base) + float(exento) + float(iva) - float(total)) > 0.05:
            malas_iva_c.append((int(cid), float(base) + float(exento) + float(iva), float(total)))
    L.ok(not malas_cr, f'V5 · crédito fiscal solo en las facturas formales ({len(filas)} compras; sin crédito = 0)',
         str(malas_cr[:2]))
    L.ok(not malas_iva_c, 'V5b · libro de compras: base + exento + IVA = total por factura', str(malas_iva_c[:2]))

    # ── c6 · retenciones de ISLR (Decreto 1808) ───────────────────────────────
    L.empezar('c6 · Retenciones de ISLR: porcentajes y sustraendo del Decreto 1808')
    registradas = []
    for i, r in enumerate(datos['islr']):
        try:
            cur.execute('SELECT tienda.fn_registrar_retencion_islr(fn_hoy(), %s, %s, %s, %s, %s, %s, %s, %s, NULL, %s, %s)',
                        (r['beneficiario'], r['rif'], r['tipo'], r['concepto'],
                         f'F-{r["concepto"][:3]}-{random_ret_numero(r["base_ves"] + i)}',
                         r['base_ves'], 'VES', r['fondos'], r['beneficiario'][:40], uid))
            registradas.append((cur.fetchone()[0], r))
            L.commit()
        except psycopg2.Error as e:
            L.rollback()
            L.ok(False, f'ISLR {r["concepto"]} {r["tipo"]} falló', (e.diag.message_primary or '')[:110])
    L.ok(len(registradas) == len(datos['islr']), f'{len(registradas)} retenciones de ISLR registradas')

    ut_vig = float(q(cur, 'SELECT valor_ves FROM tienda.islr_ut ORDER BY desde DESC LIMIT 1')[0][0])
    ut_fn = float(q(cur, 'SELECT tienda.fn_islr_ut(fn_hoy())')[0][0])
    L.ok(abs(ut_vig - ut_fn) < 0.001 and ut_vig > 0,
         f'V7a · fn_islr_ut(hoy) devuelve la última UT registrada (vigencia por fecha: Bs {ut_vig})')

    filas = q(cur, """SELECT r.id, r.tipo_beneficiario, r.concepto, r.base_ves, r.porcentaje,
                             r.sustraendo_ves, r.retenido_ves
                      FROM tienda.retenciones_islr r WHERE r.estado = 'ACTIVA'""")
    pcts = dict(q(cur, "SELECT codigo, pct_natural FROM tienda.islr_conceptos") + [])
    pcts_j = dict(q(cur, "SELECT codigo, pct_juridica FROM tienda.islr_conceptos"))
    cfg = q(cur, 'SELECT sustraendo_una_vez_mes, factor_sustraendo FROM tienda.islr_config')[0]
    malas = []
    for (rid, tipo, concepto, base, pct, sus, ret) in filas:
        pct_esperado = float(pcts[concepto] if tipo == 'N' else pcts_j[concepto])
        if abs(float(pct) - pct_esperado) > 0.001:
            malas.append((int(rid), concepto, tipo, float(pct), pct_esperado))
            continue
        sus_esperado = r2(ut_vig * pct_esperado / 100 * float(cfg[1])) if tipo == 'N' else 0.0
        if abs(float(sus) - sus_esperado) > 0.01:
            malas.append((int(rid), concepto, tipo, 'sustraendo', float(sus), sus_esperado))
            continue
        e_ret = max(r2(float(base) * pct_esperado / 100 - float(sus)), 0)
        if abs(float(ret) - e_ret) > 0.01:
            malas.append((int(rid), concepto, tipo, float(ret), e_ret))
    L.ok(not malas, f'V7b · {len(filas)} retenciones ISLR: % del Decreto 1808 + sustraendo '
                    f'(UT × % × 83,3334) + retención ≥ 0 exactos', str(malas[:2]))
    # honorarios 3/5 y servicios 1/2 según la tabla oficial (UT 43)
    ho = q(cur, "SELECT tipo_beneficiario, porcentaje FROM tienda.retenciones_islr r JOIN tienda.islr_conceptos c ON c.codigo = r.concepto WHERE c.codigo='HONORARIOS' LIMIT 2")
    co = q(cur, "SELECT tipo_beneficiario, porcentaje FROM tienda.retenciones_islr r JOIN tienda.islr_conceptos c ON c.codigo = r.concepto WHERE c.codigo='CONTRATISTAS' LIMIT 2")
    L.ok(all((t == 'N' and float(p) == 3.0) or (t == 'J' and float(p) == 5.0) for t, p in ho),
         'V7c · honorarios profesionales: 3 % natural / 5 % jurídica (Decreto 1808, 9.1.b)')
    L.ok(all((t == 'N' and float(p) == 1.0) or (t == 'J' and float(p) == 2.0) for t, p in co),
         'V7d · servicios/contratistas: 1 % natural / 2 % jurídica (Decreto 1808, 9.11)')

    # ── c7 · contabilidad: partida doble y cruce con los libros ───────────────
    L.empezar('c7 · Contabilidad: partida doble por asiento y global, cruce con libros')
    descuadrados = q(cur, """SELECT id FROM (SELECT a.id, sum(l.debe) d, sum(l.haber) h
                             FROM tienda.asientos a JOIN tienda.asiento_detalle l ON l.asiento_id = a.id
                             GROUP BY a.id) x WHERE ABS(d - h) > 0.01""")
    L.ok(not descuadrados, 'V8 · TODOS los asientos cuadran (debe = haber por asiento)')
    global_dh = q(cur, 'SELECT COALESCE(sum(debe),0), COALESCE(sum(haber),0) FROM tienda.asiento_detalle')[0]
    L.ok(abs(float(global_dh[0]) - float(global_dh[1])) < 0.05,
         f'V8b · partida doble global: Bs {float(global_dh[0]):,.2f} = Bs {float(global_dh[1]):,.2f}')
    sin_asiento = q(cur, """SELECT v.id FROM tienda.ventas v
                            LEFT JOIN tienda.asientos a ON a.origen_tipo = 'VENTA' AND a.origen_id = v.id
                            WHERE v.estado = 'COMPLETADA' AND v.id > %s AND a.id IS NULL""", (snap_venta,))
    L.ok(not sin_asiento, f'V8c · cada venta COMPLETADA tiene su asiento automático ({len(ids_venta) - len(anuladas)} asientos)',
         str([int(r[0]) for r in sin_asiento][:8]))
    iva_mayor = float(q(cur, """SELECT COALESCE(sum(l.haber - l.debe),0) FROM tienda.asiento_detalle l
                          JOIN tienda.plan_cuentas c ON c.id = l.cuenta_id
                          JOIN tienda.asientos a ON a.id = l.asiento_id
                          WHERE c.codigo = '2.1.02' AND a.origen_tipo IN ('VENTA','ANULACION_VENTA')
                            AND a.origen_id > %s""", (snap_venta,))[0][0])
    iva_libro = float(q(cur, 'SELECT COALESCE(sum(iva_ves),0) FROM tienda.ventas WHERE estado = %s AND id > %s',
                        ('COMPLETADA', snap_venta))[0][0])
    L.ok(abs(iva_mayor - iva_libro) < 1.0,
         f'V8d · IVA débito del mayor 2.1.02 (Bs {iva_mayor:,.2f}) = IVA del libro de ventas (Bs {iva_libro:,.2f})',
         f'mayor={iva_mayor:.2f} libro={iva_libro:.2f}')
    iva_cred_mayor = float(q(cur, 'SELECT COALESCE(sum(l.debe - l.haber),0) FROM tienda.asiento_detalle l '
                          'JOIN tienda.plan_cuentas c ON c.id = l.cuenta_id '
                          'JOIN tienda.asientos a ON a.id = l.asiento_id '
                          'WHERE c.codigo = (SELECT codigo FROM tienda.plan_cuentas WHERE id = tienda.fn_cuenta_param(\'IVA_CREDITO\')) '
                          'AND a.origen_tipo = \'COMPRA\' AND a.origen_id > %s', (snap_compra,))[0][0])
    iva_libro_compras = float(q(cur, 'SELECT COALESCE(sum(credito_fiscal_ves),0) FROM tienda.v_libro_compras WHERE estado = %s AND compra_id > %s', ('REGISTRADA', snap_compra))[0][0])
    L.ok(abs(iva_cred_mayor - iva_libro_compras) < 1.0,
         f'V8e · IVA crédito fiscal del mayor (Bs {iva_cred_mayor:,.2f}) = IVA del libro de compras (Bs {iva_libro_compras:,.2f})',
         f'mayor={iva_cred_mayor:.2f} libro={iva_libro_compras:.2f}')

    # ── c8 · inventario e invariantes ─────────────────────────────────────────
    L.empezar('c8 · Inventario (kardex/stock) e invariantes globales')
    malas_stock = []
    filas = q(cur, 'SELECT id, stock_actual FROM tienda.productos')
    for pid, actual in filas:
        movs = q(cur, """SELECT COALESCE(sum(cantidad),0) FROM tienda.movimientos_inventario
                         WHERE producto_id = %s AND id > %s""", (pid, snap_mov))[0][0]
        esperado = float(stock_inicial.get(pid, 0)) + float(movs)
        if abs(float(actual) - esperado) > 0.001:
            malas_stock.append((int(pid), float(actual), esperado))
    L.ok(not malas_stock, f'V9 · stock de {len(filas)} productos = inventario inicial + kardex firmado',
         str(malas_stock[:2]))
    L.ok(bool(q(cur, 'SELECT bool_and(stock_actual >= 0) FROM tienda.productos')[0][0]),
         'V9b · ningún producto con stock negativo')

    # V10 · el correlativo no se renumera ni duplica. Los huecos SON legítimos: los intentos
    # que abortan después del INSERT (límites de t06/t14) consumen el número de la secuencia,
    # y así se diseña: t15 los tolera y f14 fiscal verifica el hueco esperado. Lo que NUNCA
    # puede pasar es un número duplicado (renumeración) o una venta completada sin número.
    huecos = int(q(cur, 'SELECT count(*) FROM tienda.v_ventas_huecos')[0][0])
    duplicados = q(cur, 'SELECT numero, count(*) FROM tienda.ventas WHERE numero IS NOT NULL GROUP BY numero HAVING count(*) > 1')
    sin_numero = q(cur, "SELECT id FROM tienda.ventas WHERE estado IN ('COMPLETADA', 'ANULADA') AND numero IS NULL")
    L.ok(not duplicados and not sin_numero,
         f'V10 · correlativo de ventas sin renumeración ni duplicados ({len(ids_venta)} ventas numeradas, {huecos} huecos por rollbacks intencionales)',
         f'duplicados={duplicados[:2]} sin_numero={sin_numero[:2]}')

    imposibles = q(cur, """SELECT v.id FROM tienda.ventas v WHERE v.estado = 'COMPLETADA' AND v.id > %s
                           AND v.es_credito = false
                           AND ABS((SELECT COALESCE(sum(p.monto_usd),0) FROM tienda.pagos_venta p
                                    JOIN tienda.metodos_pago m ON m.id = p.metodo_pago_id
                                    WHERE p.venta_id = v.id AND m.es_divisa)
                                   + (SELECT COALESCE(sum(p.monto_ves),0)/v.tasa_cambio FROM tienda.pagos_venta p
                                      JOIN tienda.metodos_pago m ON m.id = p.metodo_pago_id
                                      WHERE p.venta_id = v.id AND NOT m.es_divisa)
                                   - v.total_usd) > 0.05""", (snap_venta,))
    L.ok(not imposibles, 'V11 · en las ventas de contado los pagos cuadran con el total')
    malcredito = q(cur, """SELECT v.id FROM tienda.ventas v WHERE v.estado = 'COMPLETADA' AND v.id > %s
                            AND v.es_credito = true
                            AND (SELECT COALESCE(sum(monto_usd),0) FROM tienda.pagos_venta WHERE venta_id = v.id)
                                > v.total_usd + 0.05""", (snap_venta,))
    L.ok(not malcredito, 'V11b · en las ventas a crédito los pagos nunca superan el total (el resto queda en CxC)')
    sin_cxc = q(cur, """SELECT v.id FROM tienda.ventas v WHERE v.estado = 'COMPLETADA' AND v.id > %s
                          AND v.es_credito = true
                          AND (v.total_usd - (SELECT COALESCE(sum(monto_usd),0) FROM tienda.pagos_venta WHERE venta_id = v.id)) > 0.05
                          AND NOT EXISTS (SELECT 1 FROM tienda.cuentas_por_cobrar WHERE venta_id = v.id)""", (snap_venta,))
    L.ok(not sin_cxc, 'V11c · toda venta a crédito con saldo tiene su cuenta por cobrar')

    total_ok = sum(v[0] for v in L.CONTEO.values())
    total_fail = sum(v[1] for v in L.CONTEO.values())
    print(f'\nRESUMEN t20: {total_ok} OK · {total_fail} FAIL')
    L.rollback()
    return 0 if total_fail == 0 else 1


def random_ret_numero(seed):
    return f'{(int(seed) * 7919 + int(time.time())) % 10**8:08d}'


if __name__ == '__main__':
    sys.exit(main())
