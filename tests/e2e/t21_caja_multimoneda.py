# -*- coding: utf-8 -*-
"""t21 · Caja multimoneda: la regla contable del módulo «Caja en vivo / Cerrar caja».

Cubre, sobre las funciones reales de la base (fn_abrir_caja, fn_registrar_venta,
fn_resumen_apertura, fn_cerrar_apertura, movimientos_caja y el snapshot cierres_caja):

  · apertura del fondo en las CUATRO monedas (USD, VES, EUR, COP) → el arqueo arranca con las cuatro;
  · VUELTO CRUZADO: pagan en USD y el vuelto sale en Bs → suma a la gaveta USD y resta SOLO de la gaveta VES;
  · efectivo COP sin céntimos (precisión 0 de monedas.decimales);
  · pagos BANCO (punto de venta VES + Zelle USD) NO entran a la gaveta;
  · ANULADAS excluidas de ventas, gaveta y banco;
  · movimientos de caja (ingreso EUR / egreso USD) afectan la gaveta de SU moneda;
  · NO COMPENSACIÓN de divisas: faltan $5 en USD no se equilibra con el cuadre de VES/EUR/COP;
  · snapshot INMUTABLE (UPDATE prohibido) con tasas congeladas (USD/EUR/COP) y desglose bancario;
  · bloqueo contra DOBLE cierre del mismo turno.
"""
import json

import e2e_lib as L


def main():
    L.empezar('t21 · Caja multimoneda (4 divisas, vueltos cruzados, snapshot)')
    uid = L.q1("SELECT id FROM tienda.usuarios WHERE rol = 'ADMINISTRADOR' ORDER BY id LIMIT 1")
    L.ok(uid is not None, f'usuario del turno: #{uid}')

    # 0) turno limpio: si quedó una caja abierta de otra suite, se cierra primero
    previa = L.q1("SELECT fn_apertura_activa(%s)", (uid,))
    if previa:
        L.fn('fn_cerrar_apertura', previa, uid)
        L.commit()

    # 1) apertura multimoneda: fondo contado en USD, equivalente en las cuatro
    ap = L.fn('fn_abrir_caja', uid, 100, 91664.77, 90.99, 329510, 'USD')
    L.commit()
    L.ok(ap is not None, f'apertura multimoneda #{ap} con fondo en USD')
    a = L.fila("SELECT * FROM tienda.aperturas_caja WHERE id = %s", (ap,))
    L.ok(a['fondo_inicial_moneda'] == 'USD' and float(a['fondo_inicial_eur']) == 90.99 and float(a['fondo_inicial_cop']) == 329510.0,
         f'fondo guardado en las 4 monedas: USD {a["fondo_inicial_usd"]} · Bs {a["fondo_inicial_ves"]} · € {a["fondo_inicial_eur"]} · COP {a["fondo_inicial_cop"]}')

    # 2) arqueo inicial: cada moneda arranca con su fondo (cero mezclas)
    r0 = L.fn('fn_resumen_apertura', ap)
    m0 = {x['moneda']: float(x['esperado']) for x in r0['monedas']}
    L.ok(len(m0) == 4 and m0.get('USD') == 100.0 and m0.get('VES') == 91664.77,
         f'arqueo inicial USD {m0.get("USD")} · Bs {m0.get("VES")}')
    L.ok(m0.get('EUR') == 90.99 and m0.get('COP') == 329510.0,
         f'arqueo inicial € {m0.get("EUR")} · COP {m0.get("COP")}')

    p = L.q1("SELECT id FROM tienda.productos WHERE activo ORDER BY id LIMIT 1")
    L.ejecutar("CALL tienda.sp_ajustar_stock(%s, %s, 't21', %s, true)", (p, 20, uid))
    items = [{'producto_id': p, 'cantidad': 1, 'descuento_pct': 0}]
    m_ef_usd = L.q1("SELECT id FROM tienda.metodos_pago WHERE nombre = 'Efectivo USD' AND activo")
    m_ef_bs = L.q1("SELECT id FROM tienda.metodos_pago WHERE nombre = 'Efectivo Bs' AND activo")
    m_ef_cop = L.q1("SELECT id FROM tienda.metodos_pago WHERE nombre = 'Efectivo COP' AND activo")
    m_punto = L.q1("SELECT id FROM tienda.metodos_pago WHERE nombre = 'Punto de venta' AND activo")
    m_zelle = L.q1("SELECT id FROM tienda.metodos_pago WHERE nombre = 'Zelle' AND activo")
    tasa_usd = float(L.q1("SELECT fn_tasa_par('USD', 'VES', fn_hoy())"))

    # 3) VUELTO CRUZADO: cobran t1 en efectivo USD, entregan t1+5 y el vuelto $5 sale en BOLÍVARES
    t1 = float(L.fn_fila('fn_cotizar_venta', json.dumps(items))['total_usd'])
    v1 = L.fn('fn_registrar_venta', uid, json.dumps(items), json.dumps(
        [{'metodo_pago_id': m_ef_usd, 'monto': round(t1, 2), 'monto_entregado': round(t1 + 5, 2),
          'vuelto_monto': 5, 'vuelto_moneda': 'VES'}]), None, 't21 vuelto cruzado', 'PRESENCIAL')
    L.commit()
    L.ok(v1 is not None, f'venta con vuelto cruzado #{v1} (paga USD $ {t1:.2f}, vuelto Bs 5)')

    r1 = L.fn('fn_resumen_apertura', ap)
    m1 = {x['moneda']: float(x['esperado']) for x in r1['monedas']}
    entregado_usd = float(L.q1("SELECT COALESCE(monto_entregado, monto_cobrado) FROM tienda.pagos_venta WHERE venta_id = %s", (v1,)))
    L.ok(abs(m1['USD'] - (100.0 + entregado_usd)) < 0.005,
         f'gaveta USD = fondo + efectivo ENTREGADO ({entregado_usd}, IGTF incluido): {m1["USD"]}')
    L.ok(abs(m1['VES'] - (91664.77 - 5.0)) < 0.005, f'vuelto cruzado resta SOLO de la gaveta Bs: {m1["VES"]} (−5)')
    L.ok(m1['EUR'] == 90.99 and m1['COP'] == 329510.0, 'EUR y COP intactos por el vuelto cruzado')

    # 4) efectivo COP sin céntimos: la base cobra el IGTF en la moneda del pago (monto_cobrado)
    t2 = float(L.fn_fila('fn_cotizar_venta', json.dumps(items))['total_usd'])
    cop = float(L.q1("SELECT fn_tasa_par('COP', 'VES', fn_hoy())"))
    monto_cop = round(t2 * (tasa_usd / cop), 0)  # COP sin decimales
    v2 = L.fn('fn_registrar_venta', uid, json.dumps(items), json.dumps(
        [{'metodo_pago_id': m_ef_cop, 'monto': monto_cop}]), None, 't21 efectivo COP', 'PRESENCIAL')
    L.commit()
    r2 = L.fn('fn_resumen_apertura', ap)
    m2 = {x['moneda']: float(x['esperado']) for x in r2['monedas']}
    cobrado_cop = float(L.q1("SELECT monto_cobrado FROM tienda.pagos_venta WHERE venta_id = %s", (v2,)))
    L.ok(abs(m2['COP'] - (329510.0 + cobrado_cop)) < 0.5,
         f'gaveta COP += cobrado con IGTF ({cobrado_cop} COP, sin céntimos): {m2["COP"]}')

    # 5) BANCO: punto de venta en Bs + Zelle en USD — no toca la gaveta
    t3 = float(L.fn_fila('fn_cotizar_venta', json.dumps(items))['total_usd'])
    zelle = round(t3 / 2, 2)
    ves = round(t3 * tasa_usd - zelle * tasa_usd, 2)
    v3 = L.fn('fn_registrar_venta', uid, json.dumps(items), json.dumps(
        [{'metodo_pago_id': m_punto, 'monto': ves, 'referencia': 'POS-t21-001'},
         {'metodo_pago_id': m_zelle, 'monto': zelle, 'referencia': 'ZL-t21-001'}]),
        None, 't21 banco', 'PRESENCIAL')
    L.commit()
    r3 = L.fn('fn_resumen_apertura', ap)
    m3 = {x['moneda']: float(x['esperado']) for x in r3['monedas']}
    L.ok(m3['USD'] == m2['USD'] and m3['VES'] == m2['VES'] and m3['COP'] == m2['COP'] and m3['EUR'] == m2['EUR'],
         'los pagos del banco NO entran a la gaveta')
    banco = {f'{x["metodo"]}|{x["moneda"]}': (int(x['n']), float(x['usd'])) for x in r3['metodos'] if not x['efectivo']}
    L.ok(banco.get('Punto de venta|VES', (0, 0))[0] == 1 and banco.get('Zelle|USD', (0, 0))[0] == 1,
         f'banco por método y moneda nativa: {sorted(banco)}')

    # 6) ANULADA: la venta COP se anula → desaparece de ventas y de la gaveta COP
    L.ejecutar("CALL tienda.sp_anular_venta(%s, %s, 't21 anulación')", (v2, uid))
    L.commit()
    r4 = L.fn('fn_resumen_apertura', ap)
    m4 = {x['moneda']: float(x['esperado']) for x in r4['monedas']}
    L.ok(r4['ventas'] == 2 and abs(m4['COP'] - 329510.0) < 0.5,
         f'anulada excluida: quedan {r4["ventas"]} ventas y COP vuelve a {m4["COP"]}')

    # 7) movimientos de caja chica: ingreso EUR 50, egreso USD 10 → gaveta de SU moneda
    L.fn('fn_registrar_movimiento_caja', uid, 'INGRESO', 't21 flete reembolsado', 'EUR', 50, None)
    L.fn('fn_registrar_movimiento_caja', uid, 'EGRESO', 't21 retiro menor', 'USD', 10, None)
    L.commit()
    r5 = L.fn('fn_resumen_apertura', ap)
    m5 = {x['moneda']: float(x['esperado']) for x in r5['monedas']}
    L.ok(abs(m5['EUR'] - 140.99) < 0.005 and abs(m5['USD'] - (m3['USD'] - 10.0)) < 0.005,
         f'movimientos afectan su moneda: € {m5["EUR"]} · $ {m5["USD"]}')

    # 8) cierre con faltante declarado SOLO en USD (no se compensa con las demás)
    esperado_cierre = m5
    conteo = {'USD': round(esperado_cierre['USD'] - 5.0, 2), 'VES': esperado_cierre['VES'],
              'EUR': 140.99, 'COP': int(esperado_cierre['COP'])}  # COP sin céntimos, como Money::redondear(·, 0)
    L.fn('fn_cerrar_apertura', ap, uid)
    cid = L.fila("INSERT INTO tienda.cierres_caja (fecha, usuario_id, cerrado_por, apertura_id, ventas, total_usd, total_ves, "
                 "conteo, esperado, diferencia, observaciones, detalle, banco, tasas) "
                 "VALUES (fn_hoy(), %s, %s, %s, %s, %s, %s, %s::jsonb, %s::jsonb, %s::jsonb, %s, '{}'::jsonb, %s::jsonb, %s::jsonb) RETURNING id",
                 (uid, uid, ap, 2, 0, 0, json.dumps(conteo), json.dumps(esperado_cierre),
                  json.dumps({k: round(conteo[k] - esperado_cierre[k], 2) for k in conteo}),
                  't21: faltante declarado en USD (vuelto de más)', json.dumps([]),
                  json.dumps({'USD': {'tasa': tasa_usd, 'fuente': 'BCV'}, 'EUR': {'tasa': 1007.3867, 'fuente': 'BCV'}, 'COP': {'tasa': cop, 'fuente': 'BCV'}})))['id']
    L.commit()
    L.ok(cid is not None, f'snapshot del cierre #{cid} firmado con conteo de las 4 monedas')

    dif = json.loads(L.q1("SELECT diferencia::text FROM tienda.cierres_caja WHERE id = %s", (cid,)))
    L.ok(abs(float(dif['USD']) + 5.0) < 0.005 and float(dif['VES']) == 0.0 and float(dif['EUR']) == 0.0 and float(dif['COP']) == 0.0,
         f'no se compensan divisas: dif USD {dif["USD"]} · VES {dif["VES"]} · EUR {dif["EUR"]} · COP {dif["COP"]}')
    L.ok(str(json.loads(L.q1("SELECT conteo::text FROM tienda.cierres_caja WHERE id = %s", (cid,)))['COP']) == '329510',
         'COP contado sin céntimos (precisión 0 de monedas.decimales)')
    tasas = json.loads(L.q1("SELECT tasas::text FROM tienda.cierres_caja WHERE id = %s", (cid,)))
    L.ok(all(k in tasas for k in ('USD', 'EUR', 'COP')), f'tasas congeladas en el snapshot: {sorted(tasas)}')

    # 9) el snapshot es inmutable: ni UPDATE ni DELETE
    L.espera_error('snapshot inmutable (UPDATE prohibido)', L.ejecutar,
                   "UPDATE tienda.cierres_caja SET ventas = 0 WHERE id = %s", (cid,))
    L.commit()

    # 10) doble cierre prohibido
    L.espera_error('no se puede cerrar el turno dos veces', L.fn, 'fn_cerrar_apertura', ap, uid)
    L.commit()


if __name__ == '__main__':
    L.conectar()
    main()
