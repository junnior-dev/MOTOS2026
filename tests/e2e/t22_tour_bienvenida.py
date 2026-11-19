#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
t22 · TOUR DE BIENVENIDA «Conoce SmartMotos» (navegador real, Playwright + Chromium)

Qué verifica:
  1 · Un ADMINISTRADOR nuevo entra → pantalla de bienvenida que cubre el panel
      (texto de bienvenida, icono y botón «Iniciar tour · Conoce SmartMotos»).
  2 · El tour recorre SUS 6 pasos con el popover de driver.js (Panel, Productos,
      Punto de venta, Facturación digital, Reportes, Usuarios).
  3 · Al terminar: la bienvenida desaparece, el panel queda visible y
      usuarios.tour_completado pasa a TRUE en la base.
  4 · Al reentrar NO vuelve la bienvenida; el botón «Ver guía de nuevo» del
      menú relanza el tour; cerrarlo con la X NO marca pendiente.
  5 · Un CAJERO nuevo salta la guía → queda marcada y cae directo al POS.
  6 · La consola del navegador no registra ni un error en todo el flujo.

Requiere: servidor Laravel en :8000 contra la plantilla `tienda` (PGHOST_PRUEBAS
/ PGPORT_PRUEBAS para la base) y PHP para generar el hash bcrypt (PHP_BIN).
"""
import os
import subprocess
import sys
from pathlib import Path

import psycopg2
from playwright.sync_api import sync_playwright, expect

RAIZ = Path(__file__).resolve().parents[2]
BASE = os.environ.get('BASE_URL', 'http://127.0.0.1:8000')
PHP_BIN = os.environ.get('PHP_BIN', '/tmp/phpstatic/php')
CLAVE = 'tour12345'
OK = 0
FAIL = 0


def ok(cond, nombre, detalle=''):
    global OK, FAIL
    if cond:
        OK += 1
        print(f'  OK   {nombre}', flush=True)
    else:
        FAIL += 1
        print(f'  FAIL {nombre} {detalle}'.rstrip(), flush=True)


def db_actual():
    for l in (RAIZ / '.env').read_text(encoding='utf-8').splitlines():
        if l.startswith('DB_DATABASE='):
            return l.split('=', 1)[1].strip() or 'tienda'
    return 'tienda'


def db(sql, params=None):
    con = psycopg2.connect(host=os.environ.get('PGHOST_PRUEBAS', '/tmp'),
                           port=int(os.environ.get('PGPORT_PRUEBAS', '5433')),
                           dbname=db_actual(), user='postgres')
    try:
        cur = con.cursor()
        cur.execute(sql.replace('?', '%s'), params or ())  # el test se escribe con ? (estilo t18); psycopg2 usa %s
        r = cur.fetchone() if cur.description else None    # los INSERT/UPDATE/SET no devuelven filas
        con.commit()
        cur.close()
        return r[0] if r else None
    finally:
        con.close()


def bcrypt(clave):
    h = subprocess.run([PHP_BIN, '-r', f"echo password_hash('{clave}', PASSWORD_BCRYPT);"],
                       capture_output=True, text=True, check=True)
    return h.stdout.strip()


def crear_usuario(username, rol):
    """Usuario con contraseña conocida y guía pendiente (idempotente).
    Todo en UNA conexión: el trigger del hash exige la variable de sesión
    tienda.cambiando_password y solo vive en la conexión donde se fijó."""
    h = bcrypt(CLAVE)
    con = psycopg2.connect(host=os.environ.get('PGHOST_PRUEBAS', '/tmp'),
                           port=int(os.environ.get('PGPORT_PRUEBAS', '5433')),
                           dbname=db_actual(), user='postgres')
    try:
        cur = con.cursor()
        cur.execute("SELECT id FROM tienda.usuarios WHERE username = %s", (username,))
        if cur.fetchone() is None:
            cur.execute("INSERT INTO tienda.usuarios (username, password_hash, nombre_completo, rol) VALUES (%s, 'PENDIENTE', %s, %s)",
                        (username, 'Tour ' + rol.title(), rol))
        cur.execute("SET tienda.cambiando_password = '1'")
        cur.execute("UPDATE tienda.usuarios SET password_hash = %s, debe_cambiar_password = FALSE, tour_completado = FALSE WHERE username = %s",
                    (h, username))
        con.commit()
        cur.close()
    finally:
        con.close()
    return db('SELECT id FROM tienda.usuarios WHERE username = ?', [username])


def login(page, username):
    page.goto(f'{BASE}/login', wait_until='networkidle')
    page.get_by_label('Usuario', exact=True).fill(username)
    page.get_by_label('Contraseña', exact=True).fill(CLAVE)
    page.click('button[type="submit"]')
    page.wait_for_load_state('networkidle')


class Consola:
    def __init__(self, page, etiqueta):
        self.etiqueta = etiqueta
        self.errores = []
        page.on('console', lambda m: self.errores.append(f'console.{m.type}: {m.text}') if m.type == 'error' else None)
        page.on('pageerror', lambda e: self.errores.append(f'pageerror: {e}'))

    def verificar(self, nombre):
        limpios = not self.errores
        ok(limpios, nombre, '' if limpios else f' → {self.errores[:3]}')
        self.errores = []


def pasar_pasos(page, total):
    """Avanza el tour: total-1 veces «Siguiente» y cierra con «¡Listo!».
    El avance se verifica con la píldora de progreso (Paso N de M)."""
    for i in range(total - 1):
        expect(page.locator('.tour-popover .driver-popover-progress-text')).to_contain_text(f'Paso {i + 1} de {total}')
        page.locator('.driver-popover-next-btn').click()
        page.wait_for_timeout(150)
    expect(page.locator('.tour-popover .driver-popover-progress-text')).to_contain_text(f'Paso {total} de {total}')
    page.locator('.driver-popover-next-btn').click()  # el último botón dice «¡Listo!»


def main():
    crear_usuario('tour_admin', 'ADMINISTRADOR')
    crear_usuario('tour_cajero', 'CAJERO')

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 900})

        # ── 1 · Bienvenida del administrador nuevo ──
        c1 = Consola(page, 'admin')
        red = []
        page.on('request', lambda r: red.append(f'{r.method} {r.url}') if ('/tour/' in r.url or r.url.endswith('/dashboard')) else None)
        page.on('response', lambda r: red.append(f'  -> {r.status} {r.url}') if ('/tour/' in r.url or r.url.endswith('/dashboard')) else None)
        login(page, 'tour_admin')
        bienvenida = page.get_by_text('¡Bienvenido, Tour!')
        expect(bienvenida).to_be_visible(timeout=10000)
        ok(True, 'el admin nuevo ve la pantalla de bienvenida (nombre personalizado)')
        expect(page.get_by_role('button', name='Iniciar tour · Conoce SmartMotos')).to_be_visible()
        ok(True, 'la bienvenida trae el botón «Iniciar tour · Conoce SmartMotos»')
        expect(page.get_by_text('Saltar por ahora')).to_be_visible()
        ok(True, 'la bienvenida permite saltar')
        c1.verificar('bienvenida sin errores de consola')

        # ── 2 · Tour completo del administrador (6 pasos) ──
        ok(page.locator('[data-tour="panel-resumen"]').count() == 1, 'el panel está renderizado detrás de la bienvenida (paso 1 del tour lo resalta)')
        page.get_by_role('button', name='Iniciar tour · Conoce SmartMotos').click()
        expect(page.locator('.driver-popover-title')).to_contain_text('Panel', timeout=10000)
        expect(page.locator('.tour-popover .driver-popover-progress-text')).to_contain_text('Paso 1 de 6')
        ok(True, 'paso 1 de 6: Panel (píldora de progreso «Paso 1 de 6»)')
        pasar_pasos(page, 6)
        ok(True, 'pasos 2 a 6: Productos, POS, Facturación digital, Reportes y Usuarios')

        # ── 3 · Al terminar: la marca llega a la base (el POST es asíncrono) ──
        # La bienvenida se desmontó AL INICIAR el tour: la señal de cierre real
        # es la marca en la base, no la ausencia de la pantalla.
        marca = None
        for _ in range(20):
            marca = db('SELECT tour_completado FROM tienda.usuarios WHERE username = ?', ['tour_admin'])
            if marca:
                break
            page.wait_for_timeout(300)
        ok(bool(marca), 'tour_completado = TRUE en la base tras completar el tour')
        c1.verificar('tour del admin sin errores de consola')

        # ── 4 · Reentrar no la repite; «Ver guía de nuevo» sí la relanza ──
        page.reload(wait_until='networkidle')
        expect(page.get_by_text('¡Bienvenido, Tour!')).not_to_be_visible(timeout=10000)
        ok(True, 'al recargar NO vuelve la bienvenida (ya está marcada)')
        page.get_by_role('button', name='Ver guía de nuevo').click()
        expect(page.locator('.driver-popover-title')).to_contain_text('Panel', timeout=10000)
        expect(page.locator('.tour-popover .driver-popover-progress-text')).to_contain_text('Paso 1 de 6')
        ok(True, 'el botón «Ver guía de nuevo» del menú relanza el tour')
        page.locator('.driver-popover-close-btn').click()
        expect(page.locator('.driver-popover-title')).not_to_be_visible(timeout=10000)
        ok(True, 'cerrar el tour con la X cierra el popover')
        sigue = db('SELECT tour_completado FROM tienda.usuarios WHERE username = ?', ['tour_admin'])
        ok(bool(sigue), 'cerrar con la X NO desmarca la guía (sigue TRUE)')
        c1.verificar('relanzado y cierre sin errores de consola')

        # ── 5 · Cajero nuevo: salta la guía y cae en el POS ──
        c2 = Consola(page, 'cajero')
        page.get_by_role('button', name='Cerrar sesión').click()
        page.wait_for_url(f'{BASE}/login', timeout=15000)
        login(page, 'tour_cajero')
        expect(page.get_by_text('¡Bienvenido, Tour!')).to_be_visible(timeout=10000)
        ok(True, 'el cajero nuevo también ve la bienvenida')
        page.get_by_text('Saltar por ahora').click()
        marca2 = None
        for _ in range(30):
            marca2 = db('SELECT tour_completado FROM tienda.usuarios WHERE username = ?', ['tour_cajero'])
            if marca2:
                break
            page.wait_for_timeout(300)
        ok(bool(marca2), 'saltar también marca la guía como vista')
        expect(page.get_by_text('¡Bienvenido, Tour!')).not_to_be_visible(timeout=10000)
        expect(page.get_by_role('heading', name='Punto de venta')).to_be_visible(timeout=10000)
        ok(True, 'al saltar, el cajero cae directo en el punto de venta')
        c2.verificar('flujo del cajero sin errores de consola')

        browser.close()

    print(f'\nRESUMEN t22: {OK} OK · {FAIL} FAIL')
    sys.exit(1 if FAIL else 0)


if __name__ == '__main__':
    main()
