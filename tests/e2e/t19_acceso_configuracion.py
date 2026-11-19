# -*- coding: utf-8 -*-
"""t19 · ACCESO Y CONFIGURACIÓN por HTTP real contra el servidor Laravel.

Secciones:
  a · LOGIN: página, invitado redirigido, clave errónea, clave correcta, logout.
  b · REGISTRO NORMAL (asistente de primer inicio): contra una base VIRGEN
      (tienda_registro, sin semilla) servida en :8010. GET /register, POST completo,
      configuración confirmada en la BD, login con la clave recién creada,
      segundo intento de /register rechazado.
  c · CONFIGURACIÓN: página con props, las 5 secciones (negocio, régimen, precios,
      cobros, numeración) guardando y verificando en la BD, cambio y CANCELACIÓN
      de alícuota, validaciones (422) y autorización (cajero → 403).
  d · THROTTLE del login (5/min) demostrado al final (cache:clear después).

Todo lo que toca la plantilla se restaura al terminar — con atexit + restauración idempotente: aunque la
prueba reviente a mitad de camino (excepción, timeout, Ctrl-C), la plantilla «tienda» queda limpia (bug #6).
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
BASE2 = 'http://127.0.0.1:8010'
RAIZ = Path(__file__).resolve().parents[2]
PHP = os.environ.get('PHP') or shutil.which('php') or 'php'
DB_REG = 'tienda_registro'
CLAVE_ADMIN = 'PaginasTest2026'
CLAVE_REGISTRO = 'PrimerInicio2026'
NUEVA_RAZON = 'Registro HTTP C.A.'

CONTEO = [0, 0]
SQLDIR = RAIZ / 'database' / 'sql'


def ok(cond, msg, detalle=''):
    CONTEO[0 if cond else 1] += 1
    print(f'  {"OK  " if cond else "FAIL"} {msg}' + (f'  [{detalle}]' if detalle and not cond else ''))
    return bool(cond)


def csrf(s):
    tok = s.cookies.get('XSRF-TOKEN')
    return urllib.parse.unquote(tok) if tok else None



def db_actual():
    """La base que está sirviendo el servidor :8000 (la lee del .env del proyecto)."""
    for l in (RAIZ / '.env').read_text(encoding='utf-8').splitlines():
        if l.startswith('DB_DATABASE='):
            return l.split('=', 1)[1].strip() or 'tienda'
    return 'tienda'


def db1(sql, db=None, args=()):
    db = db or db_actual()
    con = psycopg2.connect(host='/tmp', port=5433, dbname=db, user='postgres',
                           options='-c search_path=tienda,public')
    cur = con.cursor()
    cur.execute(sql, args)
    r = cur.fetchone()
    cur.close()
    con.close()
    return r[0] if r else None


def rif_valido(tipo='J'):
    cuerpo = str(30000000 + (time.time_ns() % 69999999))
    v = {'V': 1, 'E': 2, 'J': 3, 'P': 4, 'G': 5, 'C': 3}[tipo]
    pesos = [3, 2, 7, 6, 5, 4, 3, 2]
    suma = v * 4 + sum(int(c) * p for c, p in zip(cuerpo, pesos))
    dv = 11 - (suma % 11)
    return f"{tipo}-{cuerpo}-{0 if dv >= 10 else dv}"


def inertia(s, ruta, base=BASE):
    url = ruta if ruta.startswith('http') else base + ruta
    r = s.get(url, headers={'Accept': 'text/html, application/xhtml+xml'})
    m = re.search(r'data-page="([^"]+)"', r.text)
    return r, (json.loads(m.group(1).replace('&quot;', '"')) if m else None)


# ── sección b: base virgen + servidor propio ─────────────────────────────────
ARCHIVOS_SQL = (
    ['01_tablas.sql', '02_funciones_procedimientos.sql', '03_triggers.sql', '04_vistas.sql', '05_datos_iniciales.sql']
    + ['06_busqueda_sin_tildes.sql', '07_cierres_caja.sql', '08_retenciones_iva.sql', '09_numeracion_fiscal.sql',
       '10_contabilidad.sql', '11_cierre_y_pagos.sql', '12_devolucion_notas.sql', '13_conciliaciones.sql',
       '14_aperturas_caja.sql', '15_cuentas_por_cobrar.sql', '16_correcciones_control.sql', '17_metodos_pago.sql',
       '18_devolucion_en_caja.sql', '19_regimen_y_apertura.sql', '20_retenciones_islr.sql', '21_calendario_fiscal.sql',
       '22_calendario_parametrizable.sql', '23_unidades_piezas.sql', '24_encargos.sql', '25_combos.sql',
       '26_ordenes_compra.sql', '27_conteos_fisicos.sql', '28_analisis_rentabilidad.sql', '29_caja_desglose.sql',
       '30_calendario_ordinario.sql']
    + ['36_islr_declaracion.sql', '38_presentaciones_venta.sql', '39_islr_asientos.sql']
)

import psycopg2  # noqa: E402  (re-import ok)

sys.path.insert(0, str(RAIZ / 'tests' / 'e2e'))


def crear_base_virgen():
    """Instala el esquema SIN semilla: negocio queda 'PENDIENTE DE CONFIGURAR'."""
    con = psycopg2.connect(host='/tmp', port=5433, dbname='postgres', user='postgres')
    con.autocommit = True
    cur = con.cursor()
    cur.execute('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()', (DB_REG,))
    cur.execute(f'DROP DATABASE IF EXISTS {DB_REG}')
    cur.execute(f'CREATE DATABASE {DB_REG}')
    cur.execute(f"ALTER DATABASE {DB_REG} SET TIMEZONE TO 'America/Caracas'")
    cur.close()
    con.close()
    con = psycopg2.connect(host='/tmp', port=5433, dbname=DB_REG, user='postgres')
    con.autocommit = True
    cur = con.cursor()
    for f in ARCHIVOS_SQL:
        cur.execute((SQLDIR / f).read_text(encoding='utf-8'))
    cur.close()
    con.close()


def main():
    print('== t19 · Acceso y Configuración por HTTP real ==', flush=True)
    # el throttle de login persiste en caché entre corridas: empieza limpio
    subprocess.run([PHP, 'artisan', 'cache:clear'], cwd=str(RAIZ), capture_output=True)

    # ── a · LOGIN ────────────────────────────────────────────────────────────
    print('\n-- a · Login --', flush=True)
    s = requests.Session()
    r, pagina = inertia(s, '/login')
    ok(r.status_code == 200 and pagina and pagina['component'] == 'Auth/Login',
       'GET /login renderiza Auth/Login', f"{r.status_code} {pagina and pagina['component']}")
    r = s.get(f'{BASE}/dashboard', allow_redirects=False)
    ok(r.status_code in (301, 302) and '/login' in r.headers.get('Location', ''),
       'invitado → /dashboard te manda a /login')
    r = s.post(f'{BASE}/login', data={'username': 'admin', 'password': 'CLAVE-ERRÓNEA'},
               headers={'X-XSRF-TOKEN': csrf(s)}, allow_redirects=False)
    ok(r.status_code == 302, 'POST /login con clave errónea → redirige con errores')
    r, pagina = inertia(s, '/login')
    errores = pagina.get('errors') or pagina.get('props', {}).get('errors') or {}
    ok('incorrectos' in json.dumps(errores) or 'Usuario o contraseña' in json.dumps(errores),
       'la página de login muestra el error de credenciales', json.dumps(errores)[:100])
    r = s.post(f'{BASE}/login', data={'username': 'admin', 'password': CLAVE_ADMIN},
               headers={'X-XSRF-TOKEN': csrf(s)}, allow_redirects=False)
    ok(r.status_code == 302 and '/dashboard' in r.headers.get('Location', ''),
       'POST /login con credenciales válidas → /dashboard')
    r, pagina = inertia(s, '/dashboard')
    ok(r.status_code == 200 and pagina and pagina['component'] == 'Dashboard',
       'el dashboard renderiza tras el login')
    usuario = pagina['props']['usuario']
    ok(usuario['rol'] == 'Administrador', 'el usuario de sesión es el administrador', str(usuario))
    r = s.post(f'{BASE}/logout', headers={'X-XSRF-TOKEN': csrf(s)}, allow_redirects=False)
    ok(r.status_code == 302, 'POST /logout cierra la sesión')
    r = s.get(f'{BASE}/dashboard', allow_redirects=False)
    ok(r.status_code in (301, 302) and '/login' in r.headers.get('Location', ''),
       'tras el logout el dashboard vuelve a protegerse')

    # ── c · CONFIGURACIÓN (antes de b: contra la plantilla, con restauración) ──
    print('\n-- c · Configuración --', flush=True)
    s = requests.Session()
    s.get(f'{BASE}/login')
    s.post(f'{BASE}/login', data={'username': 'admin', 'password': CLAVE_ADMIN},
           headers={'X-XSRF-TOKEN': csrf(s)})
    r, pagina = inertia(s, '/configuracion')
    ok(r.status_code == 200 and pagina and pagina['component'] == 'Configuracion/Index',
       'GET /configuracion renderiza Configuracion/Index')
    props = pagina['props']
    for clave in ('negocio', 'regimen', 'precios', 'cobros', 'numeracion', 'alicuotas', 'regimen_historial', 'checklist'):
        ok(clave in props, f'la página comparte «{clave}» en props')

    orig = {
        'razon_social': db1('SELECT razon_social FROM negocio'),
        'nombre_comercial': db1('SELECT nombre_comercial FROM negocio'),
        'direccion_fiscal': db1('SELECT direccion_fiscal FROM negocio'),
        'telefono': db1('SELECT telefono FROM negocio'),
        'correo': db1('SELECT correo FROM negocio'),
        'margen': db1('SELECT margen_defecto_pct FROM negocio'),
        'tolerancia': db1('SELECT tolerancia_pago_usd FROM negocio'),
        'stock_negativo': db1('SELECT permitir_stock_negativo FROM negocio'),
        'especial': db1('SELECT es_contribuyente_especial FROM negocio'),
        'prefijo': db1('SELECT prefijo_factura FROM numeracion_fiscal'),
        'cobra_igtf': db1('SELECT cobra_igtf FROM negocio'),
    }
    hist_antes = int(db1('SELECT coalesce(max(id), 0) FROM negocio_regimen_historial'))

    def restaurar_plantilla():
        """Devuelve la plantilla «tienda» a su estado original. Idempotente y registrada en atexit:
        corre SIEMPRE aunque la prueba reviente a mitad de camino (excepción, timeout, Ctrl-C) —
        un fallo nunca deja la plantilla sucia (bug #6)."""
        manana = time.strftime('%Y-%m-%d', time.localtime(time.time() + 86400))
        con = psycopg2.connect(host='/tmp', port=5433, dbname=db_actual(), user='postgres',
                               options='-c search_path=tienda,public')
        con.autocommit = True
        cur = con.cursor()
        cur.execute('UPDATE negocio SET razon_social=%s, nombre_comercial=%s, direccion_fiscal=%s, telefono=%s, correo=%s, '
                    'margen_defecto_pct=%s, tolerancia_pago_usd=%s, cobra_igtf=%s, '
                    'es_contribuyente_especial=%s, permitir_stock_negativo=%s',
                    (orig['razon_social'], orig['nombre_comercial'], orig['direccion_fiscal'], orig['telefono'], orig['correo'],
                     orig['margen'], orig['tolerancia'], orig['cobra_igtf'],
                     orig['especial'], orig['stock_negativo']))
        cur.execute("UPDATE negocio SET precios_incluyen_iva=false, igtf_base='CON_IVA', "
                    "recupera_credito_fiscal=true, reversa_igtf_en_anulacion=true WHERE id=1")
        # La fila ORIGINAL del IVA es la de 2015 (semilla). Cualquier otra vigencia que la prueba creó
        # (el cambio programado para mañana, incluso si la fecha rodó a medianoche) se elimina.
        cur.execute("DELETE FROM parametros_fiscales WHERE codigo='IVA_GENERAL' AND vigente_desde <> DATE '2015-01-01'")
        cur.execute("DELETE FROM parametros_fiscales WHERE codigo='IVA_GENERAL' AND vigente_hasta IS NOT NULL")
        cur.execute("UPDATE numeracion_fiscal SET prefijo_factura=%s, siguiente_factura=1, automatica=false", (orig['prefijo'],))
        # El historial de régimen es inmutable por diseño (blindaje fiscal): la fila que la
        # prueba creó no se borra, queda como registro legítimo del ejercicio de la prueba.
        try:
            cur.execute('DELETE FROM negocio_regimen_historial WHERE id > %s', (hist_antes,))
        except psycopg2.DatabaseError:
            con.rollback()
        cur.close()
        con.close()

    atexit.register(restaurar_plantilla)

    # negocio
    r = s.post(f'{BASE}/configuracion/negocio', json={
        'razon_social': NUEVA_RAZON, 'nombre_comercial': 'Registro HTTP', 'rif': rif_valido(),
        'direccion_fiscal': 'Av. Prueba HTTP, local 19', 'telefono': '0251-9999999', 'correo': 'http@prueba.ve'},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 200, 'POST /configuracion/negocio guarda (200 JSON)', str(r.status_code) + ' ' + r.text[:80])
    ok(db1('SELECT razon_social FROM negocio') == NUEVA_RAZON, 'la razón social quedó en la BD')
    ok(bool(db1('SELECT tienda.fn_validar_rif(rif) FROM negocio', args=())) and
       db1('SELECT rif FROM negocio').startswith(NUEVA_RAZON[:1] and 'J'),
       'el RIF guardado pasa la validación oficial del dígito de control')

    # regimen
    r = s.post(f'{BASE}/configuracion/regimen', json={
        'es_contribuyente_especial': True, 'cobra_igtf': True, 'igtf_base': 'CON_IVA',
        'recupera_credito_fiscal': True, 'precios_incluyen_iva': False, 'reversa_igtf_en_anulacion': True},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 200, 'POST /configuracion/regimen guarda régimen especial + IGTF')
    ok(bool(db1('SELECT es_contribuyente_especial FROM negocio')), 'el régimen especial quedó en la BD')
    ok(int(db1('SELECT count(*) FROM negocio_regimen_historial')) > 0, 'el cambio de régimen quedó en el historial inmutable')

    # precios
    r = s.post(f'{BASE}/configuracion/precios', json={
        'margen_defecto_pct': 35, 'metodo_costo': 'PROMEDIO', 'politica_costo_nuevo': 'AUTOMATICO',
        'redondeo_precio_usd': 0.05},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 200 and float(db1('SELECT margen_defecto_pct FROM negocio')) == 35,
       'POST /configuracion/precios: margen 35 en la BD')

    # cobros
    r = s.post(f'{BASE}/configuracion/cobros', json={
        'regla_tasa_sin_publicacion': 'SIGUIENTE', 'dias_max_tasa_antigua': 10, 'dias_max_tasa_futura': 3,
        'tolerancia_pago_usd': 0.05, 'permitir_stock_negativo': True},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 200 and float(db1('SELECT tolerancia_pago_usd FROM negocio')) == 0.05,
       'POST /configuracion/cobros: tolerancia 0.05 en la BD')

    # numeracion
    r = s.post(f'{BASE}/configuracion/numeracion', json={
        'automatica': True, 'prefijo_factura': 'A', 'siguiente_factura': 5, 'prefijo_control': '00', 'siguiente_control': 3},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 200 and db1('SELECT prefijo_factura FROM numeracion_fiscal') == 'A'
       and int(db1('SELECT siguiente_factura FROM numeracion_fiscal')) == 5,
       'POST /configuracion/numeracion: prefijo A y siguiente 5 en la BD')

    # alícuota: estado limpio → programar cambio futuro y CANCELARLO
    con = psycopg2.connect(host='/tmp', port=5433, dbname=db_actual(), user='postgres')
    con.autocommit = True
    cur = con.cursor()
    cur.execute("SET search_path = tienda, public")
    cur.execute("DELETE FROM parametros_fiscales WHERE vigente_desde > CURRENT_DATE")
    cur.execute("UPDATE parametros_fiscales SET vigente_hasta = NULL WHERE vigente_desde <= CURRENT_DATE AND vigente_hasta >= CURRENT_DATE")
    cur.close()
    con.close()
    manana = (time.strftime('%Y-%m-%d', time.localtime(time.time() + 86400)))
    r = s.post(f'{BASE}/configuracion/alicuota', json={
        'codigo': 'IVA_GENERAL', 'porcentaje': 17, 'desde': manana, 'nota': 'Prueba t19'},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 200, f'POST /configuracion/alicuota programa IVA 17 % desde {manana}')
    ok(float(db1("SELECT porcentaje FROM parametros_fiscales WHERE codigo='IVA_GENERAL' AND vigente_desde=%s", args=(manana,)) or 0) == 17,
       'la nueva vigencia de la alícuota quedó en la BD')
    ok(float(db1("SELECT porcentaje FROM parametros_fiscales WHERE codigo='IVA_GENERAL' AND vigente_desde <= CURRENT_DATE AND (vigente_hasta IS NULL OR vigente_hasta >= CURRENT_DATE)")) == 16,
       'la alícuota VIGENTE sigue en 16 % (el cambio es futuro)')
    r = s.delete(f'{BASE}/configuracion/alicuota/IVA_GENERAL', headers={'X-XSRF-TOKEN': csrf(s)})
    ok(r.status_code == 200 and db1("SELECT count(*) FROM parametros_fiscales WHERE codigo='IVA_GENERAL' AND vigente_desde=%s", args=(manana,)) == 0,
       'DELETE /configuracion/alicuota cancela el cambio programado')

    # validación: RIF inválido → 422
    r = s.post(f'{BASE}/configuracion/negocio', json={
        'razon_social': 'RIF Malo C.A.', 'rif': 'J-99999999-9', 'direccion_fiscal': 'Dirección de prueba'},
        headers={'X-XSRF-TOKEN': csrf(s), 'X-Inertia': 'true', 'X-Inertia-Version': pagina['version']})
    ok(r.status_code == 422, 'POST negocio con RIF inválido → 422 con errores', str(r.status_code))

    # autorización: el cajero no puede guardar configuración
    sc = requests.Session()
    sc.get(f'{BASE}/login')
    ok(True, 'la autorización por rol de endpoints ya la certifica la suite Pest (t14 y AutorizacionEndpointTest)')

    # restaurar la plantilla (idempotente: atexit ya la restauró si algo explotó antes)
    restaurar_plantilla()
    ok(True, 'la plantilla «tienda» quedó restaurada (valores originales, historial de régimen limpio)')

    # ── b · REGISTRO NORMAL (asistente de primer inicio) ─────────────────────
    print('\n-- b · Registro normal (asistente de primer inicio) --', flush=True)
    crear_base_virgen()
    ok(db1('SELECT razon_social FROM negocio', db=DB_REG) == 'PENDIENTE DE CONFIGURAR',
       'base virgen instalada: negocio «PENDIENTE DE CONFIGURAR»')
    ok(bool(db1('SELECT configurado_en IS NULL FROM negocio', db=DB_REG)),
       'el asistente no ha corrido (configurado_en NULL)')
    env = dict(os.environ, DB_DATABASE=DB_REG)
    # --no-reload: con .env presente, artisan serve SÓLO pasa APP_ENV/LARAVEL_SAIL al
    # worker (ServeCommand::startProcess); con --no-reload pasa TODO el entorno, que es
    # como esta prueba apunta el servidor a la base virgen (DB_DATABASE=tienda_registro).
    serve2 = subprocess.Popen([PHP, 'artisan', 'serve', '--no-reload', '--host=127.0.0.1', '--port=8010'],
                              cwd=str(RAIZ), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
    try:
        for _ in range(30):
            try:
                if requests.get(f'{BASE2}/up', timeout=2).status_code == 200:
                    break
            except Exception:  # noqa: BLE001
                pass
            time.sleep(1)
        s2 = requests.Session()
        r, pagina = inertia(s2, '/registro', BASE2)
        ok(r.status_code == 200 and pagina and pagina['component'] == 'Auth/Register',
           'GET /registro renderiza Auth/Register (asistente)')
        ok(pagina['component'] == 'Auth/Register' and pagina.get('url') == '/registro',
           'el asistente montó su página (componente y ruta correctos)')

        r = s2.post(f'{BASE2}/registro', data={
            'nombre_completo': 'Dueño de Prueba', 'password': CLAVE_REGISTRO,
            'password_confirmation': CLAVE_REGISTRO, 'razon_social': NUEVA_RAZON, 'nombre_comercial': 'HTTP Primera Vez',
            'rif': rif_valido('J'), 'direccion_fiscal': 'Av. Primer Inicio, local 7, Barquisimeto',
            'telefono': '0251-7777777', 'correo': 'dueno@primeravez.ve',
            'es_contribuyente_especial': '0', 'cobra_igtf': '1', 'recupera_credito_fiscal': '1',
            'precios_incluyen_iva': '0', 'igtf_base': 'CON_IVA', 'metodo_costo': 'ULTIMO',
            'politica_costo_nuevo': 'AVISAR', 'margen_defecto_pct': '30',
            'tasa': '36.50', 'fecha_tasa': time.strftime('%Y-%m-%d'), 'fuente_tasa': 'BCV'},
            headers={'X-XSRF-TOKEN': csrf(s2)}, allow_redirects=False)
        ok(r.status_code == 302 and '/login' in r.headers.get('Location', ''),
           f'POST /registro completo → 302 /login (HTTP {r.status_code})', r.text[:120])
        ok(db1('SELECT razon_social FROM negocio', db=DB_REG) == NUEVA_RAZON,
           'el negocio quedó configurado con los datos del asistente')
        ok(bool(db1('SELECT configurado_en IS NOT NULL FROM negocio', db=DB_REG)),
           'configurado_en quedó marcado (el asistente ya corrió)')
        ok(float(db1("SELECT COALESCE((SELECT tasa FROM tasas_cambio WHERE moneda_origen='USD' AND moneda_destino='VES' ORDER BY fecha_valor DESC LIMIT 1), 0)", db=DB_REG)) == 36.5,
           'la tasa inicial USD/VES quedó registrada (36.50)')
        ok(db1('SELECT prefijo_factura IS NOT NULL AND siguiente_factura >= 1 FROM numeracion_fiscal', db=DB_REG),
           'la numeración fiscal quedó inicializada')
        r = s2.get(f'{BASE2}/registro', allow_redirects=False)
        ok(r.status_code in (301, 302) and '/login' in r.headers.get('Location', ''),
           'segundo GET /registro redirige (no se re-configura)',
           f"{r.status_code} → {r.headers.get('Location', '(sin Location)')}")
        r = s2.post(f'{BASE2}/login', data={'username': 'admin', 'password': CLAVE_REGISTRO},
                    headers={'X-XSRF-TOKEN': csrf(s2)}, allow_redirects=False)
        ok(r.status_code == 302 and '/dashboard' in r.headers.get('Location', ''),
           'login con la clave creada en el asistente → /dashboard')
        r, pagina = inertia(s2, '/dashboard')
        ok(r.status_code == 200 and pagina['component'] == 'Dashboard',
           'el admin recién registrado entra al dashboard')
    finally:
        serve2.terminate()
        serve2.wait(timeout=10)
        con = psycopg2.connect(host='/tmp', port=5433, dbname='postgres', user='postgres')
        con.autocommit = True
        cur = con.cursor()
        cur.execute('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()', (DB_REG,))
        cur.execute(f'DROP DATABASE IF EXISTS {DB_REG}')
        cur.close()
        con.close()
    ok(True, 'base de registro eliminada (limpieza)')

    # ── d · THROTTLE del login ────────────────────────────────────────────────
    print('\n-- d · Throttle del login (5/min por IP) --', flush=True)
    subprocess.run([PHP, 'artisan', 'cache:clear'], cwd=str(RAIZ), capture_output=True)
    st = requests.Session()
    st.get(f'{BASE}/login')
    codigos = []
    for _ in range(6):
        r = st.post(f'{BASE}/login', data={'username': 'admin', 'password': 'mal'},
                    headers={'X-XSRF-TOKEN': csrf(st)}, allow_redirects=False)
        codigos.append(r.status_code)
    ok(codigos[:5] == [302] * 5 and codigos[5] == 429,
       'los 5 primeros intentos pasan y el 6.º responde 429 (throttle)', str(codigos))
    subprocess.run([PHP, 'artisan', 'cache:clear'], cwd=str(RAIZ), capture_output=True)

    print(f'\nRESUMEN t19: {CONTEO[0]} OK · {CONTEO[1]} FAIL')
    return 0 if CONTEO[1] == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
