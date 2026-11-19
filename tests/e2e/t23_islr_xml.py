# -*- coding: utf-8 -*-
"""t23 · XML de la RELACIÓN INFORMATIVA de retenciones de ISLR que la tienda presenta
por el Portal Fiscal del SENIAT (manual TRI.GR.03.0013, Providencia 0299/2008,
porcentajes del Decreto 1.808). Valida contra las reglas del manual:

  · raíz RelacionRetencionesISLR con ATRIBUTOS RifAgente (letra V/E/J/P/G + 9 dígitos)
    y Periodo (AAAAMM) — el período del mes de la retención;
  · UN <DetalleRetencion> por retención ACTIVA (ANULADAS fuera), con el orden
    estricto del xs:sequence: RifRetenido → NumeroFactura → NumeroControl →
    CodigoConcepto → MontoOperacion → PorcentajeRetencion;
  · NumeroFactura: máx 10 alfanuméricos — con más, los ÚLTIMOS diez; sin factura → «0»;
  · NumeroControl: SOLO el secuencial numérico (máx 8: de 0001-00001234 sale
    00001234); sin control → «NA»;
  · CodigoConcepto: 3 dígitos del Anexo 6.1 según el tipo de beneficiario
    (honorarios: natural → 002, jurídica → 004);
  · MontoOperacion y PorcentajeRetencion con punto decimal y 2 decimales;
  · período sin registros → NULL (el Portal rechaza un archivo vacío);
  · RIF de beneficiario inválido → no genera el archivo y lo dice con claridad.
"""
import datetime
import re
import xml.etree.ElementTree as ET

import e2e_lib as L

RIF_OK = re.compile(r'^[A-Za-z][0-9]{9}$')
ORDEN = ['RifRetenido', 'NumeroFactura', 'NumeroControl', 'CodigoConcepto', 'MontoOperacion', 'PorcentajeRetencion']


def registrar(hoy, uid, tipo, concepto, documento, rif, monto=10000):
    beneficiario = 'Diseñador Freelance' if tipo == 'N' else 'Consultora Contable C.A.'
    return L.fn('fn_registrar_retencion_islr', str(hoy), beneficiario, rif, tipo, concepto,
                documento, monto, 'VES', 'BANCOS', None, None, uid)


def main():
    L.conectar()
    L.empezar('t23 · XML de retenciones de ISLR (Portal Fiscal SENIAT)')
    uid = L.admin()
    hoy = L.hoy()
    ano, mes = int(str(hoy)[:4]), int(str(hoy)[5:7])
    rif_negocio = str(L.q1("SELECT upper(replace(rif, '-', '')) FROM tienda.negocio WHERE id = 1"))

    # La contabilidad automática la activan t12/f15 dentro de la cadena: los
    # fixtures de esta suite (retenciones y una compra mínima de prueba) NO
    # deben generar asientos, así que se apaga y se restaura al terminar.
    automatica = L.q1("SELECT automatica FROM tienda.contab_config WHERE id = 1")
    L.ejecutar("UPDATE tienda.contab_config SET automatica = false WHERE id = 1")
    L.commit()

    # 0) UT vigente para el sustraendo + partida limpia del mes (la guardia no
    #    permite borrar retenciones: se ANULAN con motivo, como haría el usuario).
    L.ejecutar("DELETE FROM tienda.islr_ut WHERE desde = %s", (hoy,))
    L.fn('fn_islr_guardar_ut', hoy, 43, 't23: UT de prueba', uid)
    L.ejecutar("UPDATE tienda.retenciones_islr SET estado = 'ANULADA', anulada_en = now(), "
               "motivo_anulacion = 't23 limpieza' WHERE estado = 'ACTIVA' "
               "AND fecha >= date_trunc('month', %s::date)::date AND fecha <= %s::date", (hoy, hoy))
    L.commit()

    # 1) período vacío: sin archivo (el XSD exige al menos un DetalleRetencion)
    L.ok(L.fn('fn_retenciones_islr_xml', ano, mes) is None, 'período sin retenciones → NO genera archivo')

    # 2) natural · honorarios: código 002 (3 %, con sustraendo) y monto/pct del manual
    d1 = registrar(hoy, uid, 'N', 'HONORARIOS', None, 'V123456781')          # sin factura → «0», sin control → «NA»
    L.commit()
    xml = L.fn('fn_retenciones_islr_xml', ano, mes)
    L.ok(xml is not None, 'con una retención ACTIVA sí genera el XML')
    L.ok(xml.startswith('<?xml version="1.0" encoding="utf-8"?>'), 'declara la versión y codificación utf-8 del manual')
    raiz = ET.fromstring(xml)
    L.ok(raiz.tag == 'RelacionRetencionesISLR', 'el elemento raíz es RelacionRetencionesISLR')
    L.ok(raiz.get('RifAgente') == rif_negocio and RIF_OK.match(raiz.get('RifAgente') or ''),
         f'RifAgente = {raiz.get("RifAgente")}: el RIF del negocio con el patrón t_Rif')
    L.ok(raiz.get('Periodo') == f'{ano:04d}{mes:02d}', f'Periodo = {raiz.get("Periodo")} en AAAAMM')
    dets = raiz.findall('DetalleRetencion')
    L.ok(len(dets) == 1, f'un DetalleRetencion por retención ACTIVA ({len(dets)})')
    det = dets[0]
    L.ok([c.tag for c in det] == ORDEN, 'los elementos siguen el xs:sequence del manual')
    L.ok(det.findtext('RifRetenido') == 'V123456781', 'RifRetenido tal como se registró')
    L.ok(det.findtext('NumeroFactura') == '0', 'sin número de factura → «0»')
    L.ok(det.findtext('NumeroControl') == 'NA', 'sin número de control → «NA»')
    L.ok(det.findtext('CodigoConcepto') == '002', 'honorarios · natural → código 002 del Anexo 6.1')
    L.ok(det.findtext('MontoOperacion') == '10000.00', f'MontoOperacion = {det.findtext("MontoOperacion")} con punto y 2 decimales')
    L.ok(det.findtext('PorcentajeRetencion') == '3.00', 'PorcentajeRetencion = 3.00 (Decreto 1.808, 9.1.b)')
    retenido = float(L.q1("SELECT retenido_ves FROM tienda.retenciones_islr WHERE id = %s", (d1,)))
    L.ok(abs(retenido - (10000 * 0.03 - 43 * 0.03 * 83.3334)) < 0.01, f'retenido con sustraendo del Parágrafo 2° art. 9: Bs {retenido:,.2f}')

    # 3) jurídica · honorarios → 004 (5 %); factura larga → últimos 10; control de la compra
    factura = 'FAC-1234/56'
    L.ejecutar("INSERT INTO tienda.compras (proveedor_id, numero_factura, numero_control, fecha_factura, moneda, "
               "tasa_cambio, total_usd, usuario_id) VALUES ((SELECT id FROM tienda.proveedores WHERE activo "
               "ORDER BY id LIMIT 1), %s, %s, %s::date, 'USD', 1, 1000, %s)", (factura, '0001-00001234', str(hoy), uid))
    registrar(hoy, uid, 'J', 'HONORARIOS', factura, 'J308554839')            # compra con control 0001-00001234
    registrar(hoy, uid, 'N', 'COMISIONES', 'ABCDEF12345678', 'V123456781')  # 14 alfanuméricos → últimos 10
    registrar(hoy, uid, 'N', 'FLETES', 'FAC-9999/88', 'V123456781')         # control inexistente → NA
    L.commit()
    xml = L.fn('fn_retenciones_islr_xml', ano, mes)
    raiz = ET.fromstring(xml)
    dets = raiz.findall('DetalleRetencion')
    L.ok(len(dets) == 4, f'las cuatro retenciones del mes están en el archivo ({len(dets)})')
    jj = [d for d in dets if d.findtext('RifRetenido') == 'J308554839']
    L.ok(jj and jj[0].findtext('CodigoConcepto') == '004' and jj[0].findtext('PorcentajeRetencion') == '5.00',
         'honorarios · jurídica → código 004 con 5 %')
    L.ok(jj[0].findtext('NumeroControl') == '00001234', 'el control de la compra es SOLO el secuencial (0001-00001234 → 00001234)')
    L.ok(jj[0].findtext('NumeroFactura') == 'FAC123456', f'la factura se normaliza a alfanuméricos ({jj[0].findtext("NumeroFactura")})')
    com = [d for d in dets if d.findtext('CodigoConcepto') == '018']
    L.ok(com and com[0].findtext('NumeroFactura') == 'EF12345678', 'comisiones (018): 14 caracteres → los ÚLTIMOS diez')
    fl = [d for d in dets if d.findtext('CodigoConcepto') == '071']
    L.ok(fl and fl[0].findtext('NumeroControl') == 'NA', 'fletes (071) sin control en compras → «NA»')
    for d in dets:
        L.ok(RIF_OK.match(d.findtext('RifRetenido')) is not None, 'RifRetenido cumple el patrón t_Rif')
        L.ok(re.fullmatch(r'\d+\.\d{2}', d.findtext('MontoOperacion')) is not None, 'MontoOperacion con punto decimal y 2 decimales')
        L.ok(re.fullmatch(r'\d+\.\d{2}', d.findtext('PorcentajeRetencion')) is not None and float(d.findtext('PorcentajeRetencion')) <= 100,
             'PorcentajeRetencion entre 0 y 100 con 2 decimales')
        L.ok(re.fullmatch(r'[0-9]{3}', d.findtext('CodigoConcepto')) is not None, 'CodigoConcepto de 3 dígitos')

    # 4) anulada fuera del archivo
    d4 = registrar(hoy, uid, 'N', 'ALQUILER_INMUEBLES', None, 'V123456781')
    L.commit()
    L.fn('fn_anular_retencion_islr', d4, 't23: anulada para el XML', uid)
    L.commit()
    xml = L.fn('fn_retenciones_islr_xml', ano, mes)
    L.ok('057' not in (xml or '') and len(ET.fromstring(xml).findall('DetalleRetencion')) == 4,
         'la retención ANULADA no aparece en el archivo')

    # 5) el mes anterior se genera aparte (la relación es por período)
    hace_35 = datetime.date.fromisoformat(str(hoy)) - datetime.timedelta(days=35)
    otro = registrar(hace_35, uid, 'N', 'CONTRATISTAS', None, 'V123456781')
    L.commit()
    prev = L.fn('fn_retenciones_islr_xml', hace_35.year, hace_35.month)
    raiz_prev = ET.fromstring(prev)
    L.ok(raiz_prev.get('Periodo') == f'{hace_35.year:04d}{hace_35.month:02d}' and len(raiz_prev.findall('DetalleRetencion')) == 1,
         f'el mes anterior genera SU archivo con Periodo {raiz_prev.get("Periodo")}')

    # 6) RIF inválido → el archivo no se genera y el mensaje señala la retención.
    #    (La guardia prohíbe editar retenciones: el dato corrupto se inyecta
    #    deshabilitando el trigger, solo para la prueba, y se restaura luego.)
    L.ejecutar("ALTER TABLE tienda.retenciones_islr DISABLE TRIGGER trg_retenciones_islr_guardia")
    L.ejecutar("UPDATE tienda.retenciones_islr SET rif = %s WHERE id = %s", ('V123', otro))
    L.commit()
    L.espera_error('un RIF de beneficiario inválido impide generar el archivo',
                   L.fn, 'fn_retenciones_islr_xml', hace_35.year, hace_35.month, contiene='RIF de beneficiario')
    # Restaurar con el trigger AÚN deshabilitado: la guardia impide editar.
    L.ejecutar("UPDATE tienda.retenciones_islr SET rif = %s WHERE id = %s", ('V123456781', otro))
    L.commit()
    L.ejecutar("ALTER TABLE tienda.retenciones_islr ENABLE TRIGGER trg_retenciones_islr_guardia")
    L.commit()
    L.ejecutar("UPDATE tienda.contab_config SET automatica = %s WHERE id = 1", (automatica,))
    L.commit()

    L.resumen_final()


if __name__ == '__main__':
    main()
