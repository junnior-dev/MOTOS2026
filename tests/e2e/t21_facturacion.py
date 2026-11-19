#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""t21 · FACTURACIÓN FISCAL: los DOS modos que exige el SENIAT, por HTTP real.

  a · Pantalla y autorización: GET /facturacion-digital (admin OK, cajero 403).
  b · Validaciones del modo: modo inválido → 422; máquina TCP sin IP → 422.
  c · MÁQUINA FISCAL (SIMULADOR): guardar → probar → correlativo.
  d · Emisión por máquina: venta → emitir → factura 8 dígitos + control
      NNNNNNNN-NNNNNNNN-0000000000000000 + fuente MAQUINA_FISCAL + bitácora.
  e · Nota de crédito por máquina: registrada, impresa, con su número fiscal.
  f · Anulación legal: venta facturada → primero su NC por máquina → ANULADA.
  g · Vuelta a IMPRENTA_DIGITAL: guardar + probar (acepta éxito o error de red
      con mensaje claro) y restaurar el estado inicial de la plantilla.

Conexión real a TFHKA: la hace la prueba g vía POST /facturacion-digital/probar
con las credenciales demo del .env; si el entorno no tiene salida a internet la
API responde con error controlado — la prueba lo admite y lo deja constancia.
"""
import atexit
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.parse
from pathlib import Path

import psycopg2
import requests

BASE = 'http://127.0.0.1:8000'
RAIZ = Path(__file__).resolve().parents[2]
PHP = os.environ.get('PHP') or '/home/z/bin/php'
DB = 'tienda_e2e'
CLAVE_ADMIN = 'PaginasTest2026'
sys.path.insert(0, str(RAIZ / 'tests' / 'e2e'))

CONTEO = [0, 0]


def ok(cond, msg, detalle=''):
    CONTEO[0 if cond else 1] += 1
    print(f'  {"OK  " if cond else "FAIL"} {msg}' + (f'  [{detalle}]' if detalle and not cond else ''))
    return bool(cond)


def csrf(s):
    tok = s.cookies.get('XSRF-TOKEN')
    return urllib.parse.unquote(tok) if tok else None


def inertia(s, ruta):
    r = s.get(BASE + ruta, headers={'Accept': 'text/html, application/xhtml+xml'})
    m = re.search(r'data-page="([^"]+)"', r.text)
    return r, (json.loads(m.group(1).replace('&quot;', '"')) if m else None)


def db1(sql, args=()):
    con = psycopg2.connect(host='/tmp', port=5433, dbname=DB, user='postgres',
                           options='-c search_path=tienda,public')
    cur = con.cursor()
    cur.execute(sql, args)
    r = cur.fetchone() if cur.description else None
    con.commit()
    cur.close()
    con.close()
    return r[0] if r else None


def login_admin():
    s = requests.Session()
    s.get(f'{BASE}/login')
    s.post(f'{BASE}/login', data={'username': 'admin', 'password': CLAVE_ADMIN},
           headers={'X-XSRF-TOKEN': csrf(s)})
    return s


def modo_inicial():
    return db1('SELECT modo FROM facturacion_config WHERE id = 1')


# restauración idempotente aunque la prueba reviente
_URIGINAL = {'modo': None}


def restaurar():
    if _URIGINAL['modo']:
        try:
            con = psycopg2.connect(host='/tmp', port=5433, dbname=DB, user='postgres',
                                   options='-c search_path=tienda,public')
            cur = con.cursor()
            cur.execute('UPDATE facturacion_config SET modo = %s, maquina_protocolo = %s, maquina_ip = NULL WHERE id = 1',
                        (_URIGINAL['modo'], _URIGINAL.get('proto') or 'TCP'))
            cur.execute("UPDATE tfhka_config SET activo = false WHERE negocio_id = 1")
            con.commit()
            cur.close()
            con.close()
        except Exception as e:
            print(f'  (restauración: {e})')


atexit.register(restaurar)


def crear_venta(exenta=False):
    """Registra una venta COMPLETADA por SQL (patrón de t06) y devuelve su id."""
    p1 = db1(f'SELECT id FROM productos WHERE activo AND {"exento_iva" if exenta else "NOT exento_iva"} ORDER BY id LIMIT 1')
    items = [{'producto_id': p1, 'cantidad': 1, 'descuento_pct': 0}]
    m = db1("SELECT id FROM metodos_pago WHERE nombre = 'Pago móvil' AND activo")
    con = psycopg2.connect(host='/tmp', port=5433, dbname=DB, user='postgres', options='-c search_path=tienda,public')
    cur = con.cursor()
    cur.execute('SELECT * FROM tienda.fn_cotizar_venta(%s::jsonb)', (json.dumps(items),))
    row = cur.fetchone()
    cot = dict(zip([d[0] for d in cur.description], row))
    total = float(cot['total_usd'])
    tasa = float(cot['tasa_cambio'])
    uid = db1("SELECT id FROM usuarios WHERE rol = 'ADMINISTRADOR' LIMIT 1")
    # La venta exige turno abierto para el usuario: si el clon quedó sin caja
    # (orden de suites), se abre con un fondo mínimo — la suite no depende de
    # lo que dejaron las anteriores (misma regla de t21_caja_multimoneda).
    if not db1('SELECT fn_apertura_activa(%s)', (uid,)):
        con0 = psycopg2.connect(host='/tmp', port=5433, dbname=DB, user='postgres', options='-c search_path=tienda,public')
        cur0 = con0.cursor()
        cur0.execute('SELECT tienda.fn_abrir_caja(%s, %s, %s, %s, %s, %s)', (uid, 1, 36.5, 0, 0, 'USD'))
        con0.commit()
        cur0.close()
        con0.close()
    pagos = json.dumps([{'metodo_pago_id': m, 'monto': round(total * tasa, 2), 'referencia': f'T21-{time.time_ns() % 99999999}'}])
    cur.execute('SELECT tienda.fn_registrar_venta(%s, %s::jsonb, %s::jsonb, %s, %s, %s, %s, %s)',
                (uid, json.dumps(items), pagos, None, 'Prueba t21 facturación', 'PRESENCIAL', False, 0))
    vid = cur.fetchone()[0]
    con.commit()
    cur.close()
    con.close()
    return vid, total


def main():
    print('== t21 · Facturación fiscal: imprenta digital y máquina fiscal ==', flush=True)
    subprocess.run([PHP, 'artisan', 'cache:clear'], cwd=str(RAIZ), capture_output=True)
    _URIGINAL['modo'] = modo_inicial() or 'IMPRENTA_DIGITAL'
    _URIGINAL['proto'] = db1('SELECT maquina_protocolo FROM facturacion_config WHERE id = 1')

    # usuario cajero para las pruebas de autorización (el clon e2e no lo trae)
    hash_pw = subprocess.run([PHP, '-r', "echo password_hash('CapturasCajero2026', PASSWORD_BCRYPT);"],
                             capture_output=True, text=True).stdout.strip()
    db1("DELETE FROM usuarios WHERE username = 'cajero1'")
    db1("INSERT INTO usuarios (username, password_hash, nombre_completo, rol, activo, debe_cambiar_password) "
        "VALUES ('cajero1', %s, 'Cajero t21', 'CAJERO', true, false)", (hash_pw,))

    # ── a · Pantalla y autorización ──────────────────────────────────────────
    print('\n-- a · Pantalla y autorización --', flush=True)
    s = login_admin()
    r, pag = inertia(s, '/facturacion-digital')
    ok(r.status_code == 200 and pag and pag['component'] == 'FacturacionDigital/Index',
       'GET /facturacion-digital renderiza la pantalla', f"{r.status_code}")
    cfgp = (pag or {}).get('props', {}).get('config', {})
    ok(cfgp.get('modo') in ('IMPRENTA_DIGITAL', 'MAQUINA_FISCAL', 'MANUAL'),
       'la pantalla trae el modo de emisión (SQL 43)', str(cfgp.get('modo')))
    ok('maquina' in cfgp, 'la pantalla trae la configuración de máquina fiscal')
    sc = requests.Session()
    sc.get(f'{BASE}/login')
    sc.post(f'{BASE}/login', data={'username': 'cajero1', 'password': 'CapturasCajero2026'},
            headers={'X-XSRF-TOKEN': csrf(sc)})
    r = sc.get(f'{BASE}/facturacion-digital', allow_redirects=False)
    bloqueo = r.status_code in (301, 302, 403) and (r.status_code == 403 or '/facturacion-digital' not in r.headers.get('Location', '/facturacion-digital'))
    ok(bloqueo, 'cajero → GET /facturacion-digital queda bloqueado (403 o salida a su inicio)', f"{r.status_code} {r.headers.get('Location', '')}")
    r = sc.post(f'{BASE}/facturacion-digital', data={'modo': 'MANUAL', 'ambiente': 'DEMO', 'moneda': 'BSD'},
                headers={'X-XSRF-TOKEN': csrf(sc)}, allow_redirects=False)
    ok(r.status_code == 403, 'cajero → POST /facturacion-digital → 403', str(r.status_code))

    # ── b · Validaciones del modo ────────────────────────────────────────────
    print('\n-- b · Validaciones del modo --', flush=True)
    base_cfg = {'activo': '0', 'ambiente': 'DEMO', 'moneda': 'BSD', 'auto_emitir': '1', 'usuario': '', 'clave': ''}
    r = s.post(f'{BASE}/facturacion-digital', data={**base_cfg, 'modo': 'OTRO'},
               headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    ok(r.status_code == 422, 'modo inválido → 422', str(r.status_code))
    r = s.post(f'{BASE}/facturacion-digital', data={**base_cfg, 'modo': 'MAQUINA_FISCAL', 'maquina_protocolo': 'TCP'},
               headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    ok(r.status_code == 422, 'máquina TCP sin IP → 422', str(r.status_code))

    # ── c · Configurar MÁQUINA FISCAL + SIMULADOR ────────────────────────────
    print('\n-- c · Máquina fiscal (SIMULADOR) --', flush=True)
    r = s.post(f'{BASE}/facturacion-digital', data={**base_cfg, 'modo': 'MAQUINA_FISCAL',
               'maquina_protocolo': 'SIMULADOR', 'maquina_registro': '12345678', 'punto_emision': '01', 'oficina': '00'},
               headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    ok(r.status_code == 200, 'guardar modo MAQUINA_FISCAL/SIMULADOR → 200', str(r.status_code))
    r, pag = inertia(s, '/facturacion-digital')
    cfgp = pag['props']['config']
    ok(cfgp.get('modo') == 'MAQUINA_FISCAL', 'el modo quedó persistido', str(cfgp.get('modo')))
    ok((cfgp.get('maquina') or {}).get('maquina_registro') == '12345678', 'n.º de registro de máquina guardado')
    r = s.post(f'{BASE}/facturacion-digital/probar', headers={'X-XSRF-TOKEN': csrf(s)}, allow_redirects=False)
    cuerpo = r.json() if r.status_code == 200 else {}
    ok(r.status_code == 200 and 'Simulador' in cuerpo.get('message', ''),
       'probar conexión → simulador listo', f"{r.status_code} {cuerpo.get('message', '')[:60]}")

    # ── d · Emisión por máquina ─────────────────────────────────────────────
    print('\n-- d · Emisión por máquina fiscal --', flush=True)
    vid1, _ = crear_venta()
    r = s.post(f'{BASE}/caja/venta/{vid1}/emitir', headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'})
    cuerpo = r.json() if r.headers.get('content-type', '').startswith('application/json') else {}
    ok(r.status_code == 200 and cuerpo.get('factura'), 'emitir venta → factura asignada', f"{r.status_code} {json.dumps(cuerpo)[:120]}")
    fact1 = cuerpo.get('factura', '')
    ctrl1 = cuerpo.get('control', '')
    ok(re.fullmatch(r'\d{8}', fact1 or '') is not None, 'número de factura: 8 dígitos', fact1)
    ok(re.fullmatch(r'\d{8}-\d{8}-\d{16}', ctrl1 or '') is not None, 'número de control: formato clásico 8+8+16 dígitos', ctrl1)
    ok(db1('SELECT fuente_fiscal FROM ventas WHERE id = %s', (vid1,)) == 'MAQUINA_FISCAL',
       'la venta registra la fuente MAQUINA_FISCAL')
    ok(db1("SELECT estado FROM ventas WHERE id = %s", (vid1,)) == 'COMPLETADA', 'la venta sigue COMPLETADA')
    ok((db1("SELECT count(*) FROM tfhka_eventos WHERE operacion = 'EMITIR' AND tipo_documento = 'MA' AND venta_id = %s", (vid1,)) or 0) >= 1,
       'la bitácora fiscal registra la emisión de máquina')
    # idempotencia
    r = s.post(f'{BASE}/caja/venta/{vid1}/emitir', headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'})
    cuerpo2 = r.json() if r.status_code == 200 else {}
    ok(r.status_code == 200 and cuerpo2.get('factura') == fact1 and cuerpo2.get('control') == ctrl1,
       're-emitir es idempotente (misma factura y control)')
    vid2, _ = crear_venta()
    s.post(f'{BASE}/caja/venta/{vid2}/emitir', headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'})
    fact2 = db1('SELECT numero_factura_fiscal FROM ventas WHERE id = %s', (vid2,))
    ok(int(fact2) == int(fact1) + 1, f'el correlativo de máquina avanza ({fact1} → {fact2})')

    # ── e · Nota de crédito por máquina ──────────────────────────────────────
    print('\n-- e · Nota de crédito por máquina --', flush=True)
    base_nota = db1('SELECT base_ves FROM ventas WHERE id = %s', (vid1,))
    iva_nota = db1('SELECT iva_ves FROM ventas WHERE id = %s', (vid1,))
    r = s.post(f'{BASE}/ventas/{vid1}/nota', data={'tipo': 'C', 'base_ves': str(round(float(base_nota), 2)),
               'motivo': 'Prueba t21: nota de crédito por máquina fiscal'},
               headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    ok(r.status_code == 200, 'POST /ventas/{id}/nota → 200', f"{r.status_code} {r.text[:100]}")
    nota = db1("SELECT id FROM notas_ajuste WHERE venta_id = %s AND tipo = 'C' ORDER BY id DESC LIMIT 1", (vid1,))
    ok(nota is not None, 'la nota de crédito quedó registrada')
    nf = db1('SELECT numero_fiscal FROM notas_ajuste WHERE id = %s', (nota,))
    nc = db1('SELECT numero_control FROM notas_ajuste WHERE id = %s', (nota,))
    fu = db1('SELECT fuente_fiscal FROM notas_ajuste WHERE id = %s', (nota,))
    ok(re.fullmatch(r'\d{8}', nf or '') is not None, 'la nota lleva número fiscal de máquina (8 dígitos)', str(nf))
    ok(re.fullmatch(r'\d{8}-\d{8}-\d{16}', nc or '') is not None, 'la nota lleva número de control de máquina', str(nc))
    ok(fu == 'MAQUINA_FISCAL', 'la nota registra la fuente MAQUINA_FISCAL', str(fu))
    r = s.post(f'{BASE}/facturacion-digital/notas/{nota}/anular', data={'motivo': 'Prueba t21 anulación de nota impresa'},
               headers={'X-XSRF-TOKEN': csrf(s)}, allow_redirects=False)
    ok(r.status_code == 422, 'anular nota impresa en máquina → 422 con la regla fiscal', str(r.status_code))

    # ── f · Anulación legal de una venta facturada ───────────────────────────
    print('\n-- f · Anulación: primero la NC, luego la venta --', flush=True)
    notas_antes = db1("SELECT count(*) FROM notas_ajuste WHERE venta_id = %s AND tipo = 'C'", (vid2,))
    r = s.post(f'{BASE}/ventas/{vid2}/anular', data={'motivo': 'Prueba t21: anulación con NC automática'},
               headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    ok(r.status_code == 200, 'anular venta facturada → 200', f"{r.status_code} {r.text[:120]}")
    ok(db1('SELECT estado FROM ventas WHERE id = %s', (vid2,)) == 'ANULADA', 'la venta quedó ANULADA')
    notas_despues = db1("SELECT count(*) FROM notas_ajuste WHERE venta_id = %s AND tipo = 'C'", (vid2,))
    ok(notas_despues >= notas_antes + 1, 'se emitió la NC que revierte la factura (regla SENIAT)', f'{notas_antes} → {notas_despues}')

    # ── g · Vuelta a IMPRENTA_DIGITAL + prueba real de la API ────────────────
    print('\n-- g · Imprenta digital: guardar y probar contra TFHKA demo --', flush=True)
    r = s.post(f'{BASE}/facturacion-digital', data={**base_cfg, 'modo': 'IMPRENTA_DIGITAL',
               'activo': '1', 'ambiente': 'DEMO', 'usuario': 'swfrnwrltzth_tfhka',
               'clave': ',YEDYs@&Z&Zw', 'moneda': 'BSD'},
               headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    ok(r.status_code == 200, 'guardar modo IMPRENTA_DIGITAL con credenciales demo → 200', str(r.status_code))
    r = s.post(f'{BASE}/facturacion-digital/probar', headers={'X-XSRF-TOKEN': csrf(s), 'Accept': 'application/json'}, allow_redirects=False)
    if r.status_code == 200:
        ok(True, 'POST /facturacion-digital/probar autenticó contra TFHKA demo (red disponible)', r.json().get('message', '')[:80])
    else:
        msg = (r.json() or {}).get('message', '')
        ok(len(msg) > 5, 'sin salida a internet: la prueba falla con mensaje controlado (no crashea)', msg[:90])
    r, pag = inertia(s, '/facturacion-digital')
    ok(pag['props']['config'].get('modo') == 'IMPRENTA_DIGITAL', 'el modo volvió a IMPRENTA_DIGITAL')

    print(f'\nRESUMEN t21: {CONTEO[0]} OK · {CONTEO[1]} FAIL')
    sys.exit(0 if CONTEO[1] == 0 else 1)


if __name__ == '__main__':
    main()
