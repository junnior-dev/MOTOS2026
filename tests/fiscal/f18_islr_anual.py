# -*- coding: utf-8 -*-
"""f18 · CRÍTICA: ISLR anual — tarifas oficiales del art. 53 (40_islr_tarifas_modelo.sql),
carga por plantilla, impuesto exacto por tramos y revisión antes de liquidar."""
import datetime
import json as J

import e2e_lib as L
import f_lib  # noqa: F401  · fija el clon «tienda_fiscal» también en corridas standalone


def obtener(ej, clave):
    """Mapa clave → fila para fn_islr_verificaciones."""
    v = L.fn('fn_islr_verificaciones', ej)
    v = v if isinstance(v, list) else J.loads(v)
    return {x['clave']: x for x in v}


def main():
    L.empezar('f18 · ISLR anual: tarifas oficiales, carga por plantilla y revisión')
    if L.conn is None:
        L.conectar()          # soporta la corrida standalone (el runner ya conecta)
    uid = L.admin()
    hoy = L.hoy()
    ej = int(str(hoy)[:4])          # ejercicio en curso (parcial: sirve para la aritmética)
    ej_sig = ej + 1                  # ejercicio futuro: sirve para los AVISO de la revisión
    desde = f'{ej}-01-01'
    hasta = f'{ej}-12-31'

    # UT vigente Bs 43 al cierre. En la suite completa la fila ya existe (la sembró f02
    # el primer día, cuando aún no había retenciones); f18 solo la inserta si falta,
    # porque la vigencia debe ser posterior a la última retención y no futura.
    ut_hasta = L.fn('fn_islr_ut', hasta)
    if ut_hasta is None or abs(float(ut_hasta) - 43.0) > 0.001:
        ultima = L.q1("SELECT max(fecha) FROM tienda.retenciones_islr")
        hoy_d = datetime.date.fromisoformat(str(hoy))
        vigen = hoy_d
        if ultima:
            vigen = max(vigen, datetime.date.fromisoformat(str(ultima)) + datetime.timedelta(days=1))
        if vigen > datetime.date.fromisoformat(hasta):
            vigen = datetime.date.fromisoformat(hasta)
        L.ejecutar("DELETE FROM tienda.islr_ut WHERE desde = %s", (vigen,))
        L.fn('fn_islr_guardar_ut', vigen, 43, 'f18: UT de prueba', uid)
        L.commit()
        L.ok(float(L.fn('fn_islr_ut', hasta)) == 43.0, f'UT al cierre del ejercicio = 43,00 (vigencia {vigen})')
    else:
        L.ok(True, f'UT al cierre del ejercicio = 43,00 (fila existente: {float(ut_hasta)})')

    # 1) Plantillas oficiales exactas: T1 (8 tramos, reforma 2020) y T2 (3 tramos)
    m = L.fn('fn_islr_tarifa_modelo')
    m = m if isinstance(m, list) else J.loads(m)
    por_codigo = {x['codigo']: x for x in m}
    L.ok(sorted(por_codigo.keys()) == ['T1', 'T2'], 'hay dos plantillas (T1 y T2)')
    t1 = por_codigo['T1']['tramos']
    t2 = por_codigo['T2']['tramos']
    esperado_t1 = [(0, 1000, 6, 0), (1000, 1500, 9, 30), (1500, 2000, 12, 75), (2000, 2500, 16, 155),
                   (2500, 3000, 20, 255), (3000, 4000, 24, 375), (4000, 6000, 29, 575), (6000, None, 34, 875)]
    esperado_t2 = [(0, 2000, 15, 0), (2000, 3000, 22, 140), (3000, None, 34, 500)]
    L.ok(len(t1) == 8 and all((float(a['desde_ut']), a['hasta_ut'] and float(a['hasta_ut']), float(a['pct']), float(a['sustraendo_ut'])) == e for a, e in zip(t1, esperado_t1)),
         'T1: 8 tramos exactos del art. 53 (6 %…34 %, sustraendos 0/30/75/155/255/375/575/875)')
    L.ok(len(t2) == 3 and all((float(a['desde_ut']), a['hasta_ut'] and float(a['hasta_ut']), float(a['pct']), float(a['sustraendo_ut'])) == e for a, e in zip(t2, esperado_t2)),
         'T2: 15 % / 22 % (140) / 34 % (500) — sustraendo 500 UT, no 290')
    # Continuidad aritmética de ambas escalas en cada frontera
    for escala, nombre in ((esperado_t1, 'T1'), (esperado_t2, 'T2')):
        continuo = all(abs((fr_h * fr_p / 100 - fr_s) - (fr_h * sp / 100 - ss)) < 0.001
                       for (_, fr_h, fr_p, fr_s), (_, _, sp, ss) in zip(escala[:-1], escala[1:]))
        L.ok(continuo, f'{nombre}: la escala es continua en todas las fronteras')

    # 2) Aplicar T2 al ejercicio y los tramos quedan cargados
    n = L.fn('fn_islr_tarifa_modelo_aplicar', ej, 'T2')
    L.commit()
    L.ok(int(n) == 3, f'aplicar T2 → {n} tramos')
    filas = L.qd("SELECT desde_ut, hasta_ut, pct, sustraendo_ut FROM tienda.islr_tarifa_tramos WHERE ejercicio = %s ORDER BY desde_ut", (ej,))
    L.ok(len(filas) == 3 and float(filas[2]['sustraendo_ut']) == 500.0, 'tramos del ejercicio = plantilla T2 (34 % con sustraendo 500)')

    # 3) Renta controlada → impuesto exacto por tramos (T2, tramo 2: 22 % − 140)
    b = L.fn('fn_islr_borrador', ej)
    b = b if isinstance(b, dict) else J.loads(b)
    ingresos = float(b['ingresos'])
    costo = round(ingresos * 0.40, 2)
    objetivo = 107500.00          # 2.500 UT × Bs 43 → cae en el tramo de 22 % con sustraendo 140
    ajuste = round(objetivo - (ingresos - costo), 2)
    L.fn('fn_islr_declaracion_guardar', ej, desde, hasta, costo, 0, 0, ajuste, 0, 'f18: cifras controladas', uid)
    L.commit()
    b = L.fn('fn_islr_borrador', ej)
    b = b if isinstance(b, dict) else J.loads(b)
    L.ok(abs(float(b['renta_neta']) - objetivo) < 0.05, f'renta neta controlada = {b["renta_neta"]} (objetivo {objetivo})')
    L.ok(abs(float(b['renta_neta_ut']) - 2500.0) < 0.01, f'renta en UT = {b["renta_neta_ut"]} (2.500 UT)')
    L.ok(float(b['tramo']['pct']) == 22.0 and float(b['tramo']['sustraendo_ut']) == 140.0, 'tramo aplicado: 22 % con sustraendo 140')
    L.ok(abs(float(b['impuesto']) - 17630.00) < 0.05, f'impuesto = {b["impuesto"]} (2.500 × 22 % − 140 = 410 UT × 43 = 17.630,00)')

    # 4) Cambiar a T1 con la misma renta → tramo 4: 16 % − 155 → 10.535,00
    L.fn('fn_islr_tarifa_modelo_aplicar', ej, 'T1')
    L.commit()
    b = L.fn('fn_islr_borrador', ej)
    b = b if isinstance(b, dict) else J.loads(b)
    L.ok(abs(float(b['impuesto']) - 10535.00) < 0.05, f'con T1 el impuesto = {b["impuesto"]} (2.500 × 16 % − 155 = 245 UT × 43 = 10.535,00)')

    # 5) Revisión antes de liquidar: estados y mensajes
    v = obtener(ej_sig, None)     # ejercicio futuro: sin tramos, sin datos, ejercicio en curso
    L.ok(v['tarifa']['estado'] == 'AVISO' and 'plantilla' in v['tarifa']['mensaje'].lower(), 'ejercicio futuro: tarifa sin cargar → AVISO con la salida por plantillas')
    L.ok(v['datos_contador']['estado'] == 'AVISO', 'ejercicio futuro: sin datos del contador → AVISO')
    L.ok('aún está en curso' in v['plazo']['mensaje'], 'ejercicio futuro: aviso de que la definitiva se presenta al año siguiente')

    v = obtener(ej, None)
    L.ok(v['tarifa']['estado'] == 'OK' and v['datos_contador']['estado'] == 'OK', 'ejercicio con tarifa y datos: revisión en orden')
    L.ok('pérdida' not in v['tarifa']['mensaje'], 'con renta positiva no se marca como pérdida')

    # 6) Retención sufrida SIN comprobante → AVISO de comprobantes
    L.fn('fn_islr_sufrida_registrar', hoy, 'Cliente sin comprobante f18', L.rif('J'), None, None, 5000, 100, None, uid)
    L.commit()
    v = obtener(ej, None)
    L.ok(v['comprobantes']['estado'] == 'AVISO' and 'sin número de comprobante' in v['comprobantes']['mensaje'], 'retención sin comprobante → AVISO de acreditación')

    # 7) Ejercicio anterior pendiente → AVISO de ejercicios_pendientes en el ejercicio siguiente
    v = obtener(ej_sig, None)
    L.ok(v['ejercicios_pendientes']['estado'] == 'AVISO', f'{ej} sin liquidar marca pendiente al {ej_sig}')

    # 8) Guard: con el ejercicio liquidado, la plantilla se rechaza
    L.ejecutar("UPDATE tienda.islr_declaracion SET contabilizada = true WHERE ejercicio = %s", (ej,))
    L.commit()
    L.espera_error('plantilla sobre ejercicio liquidado rechazada', L.fn, 'fn_islr_tarifa_modelo_aplicar', ej, 'T2')
    L.ejecutar("UPDATE tienda.islr_declaracion SET contabilizada = false WHERE ejercicio = %s", (ej,))
    L.commit()

    # 9) Volver a T2 y reaplicar: no duplica tramos
    L.fn('fn_islr_tarifa_modelo_aplicar', ej, 'T2')
    L.commit()
    n = L.q1("SELECT count(*) FROM tienda.islr_tarifa_tramos WHERE ejercicio = %s", (ej,))
    L.ok(int(n) == 3, f'reaplicar T2 no duplica tramos ({n})')
    L.espera_error('código inválido rechazado', L.fn, 'fn_islr_tarifa_modelo_aplicar', ej, 'T9')
    L.espera_error('ejercicio inválido rechazado', L.fn, 'fn_islr_tarifa_modelo_aplicar', 1999, 'T2')


main()
