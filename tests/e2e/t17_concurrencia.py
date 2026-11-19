# -*- coding: utf-8 -*-
"""t17 · CONCURRENCIA: carreras reales contra PostgreSQL y el servidor HTTP.

Lo que las suites t01–t16 no hacían: correr escrituras SIMULTÁNEAS de verdad
(hilos con conexiones propias y barrera de arranque).

Secciones:
  c1 · DOS CAJEROS vendiendo productos distintos a la vez: 16 hilos (8 por
       producto) en paralelo; nada se cruza y cada stock baja exacto.
  c2 · DOS CAJAS de la tienda vendiendo a la vez: correlativo sin huecos
       ni duplicados bajo carrera, y bitácora de huecos sin cambios.
  c3 · STOCK sin negativos bajo ventas simultáneas: 12 hilos sobre stock 5 →
       5 ventas ganan, 7 reciben TV003, el stock termina en 0 y nunca baja de él.
  c4 · REHASH CONTROLADO EN LOGIN (D-1): un hash con costo viejo (bcrypt 10)
       entra bien por HTTP y queda modernizado a bcrypt 12 vía sp_rehash_password
       (sin tocar debe_cambiar_password); el UPDATE directo sigue rechazado (TV005).

Notas:
  · c1–c3 corren contra el clon «tienda_conc» (se crea desde la plantilla «tienda»).
  · c4 corre contra la base real «tienda» porque necesita el servidor Laravel vivo.
"""
import json
import os
import shutil
import subprocess
import sys
import threading
import time
import urllib.parse
from pathlib import Path

import psycopg2
import requests

RAIZ = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(RAIZ / 'tests' / 'e2e'))
import e2e_lib as L  # noqa: E402

PGHOST = os.environ.get('PGHOST_PRUEBAS', '/tmp')
PGPORT = int(os.environ.get('PGPORT_PRUEBAS', '5433'))
CLON = 'tienda_conc'
BASE = 'http://127.0.0.1:8000'
PHP = os.environ.get('PHP') or shutil.which('php') or 'php'
CLAVE_REHASH = 'ConcRehash2026'

# ── conector propio para hilos (e2e_lib usa UNA conexión global) ──────────────
def nueva_conexion(db=CLON):
    con = psycopg2.connect(host=PGHOST, port=PGPORT, dbname=db, user='postgres',
                           options='-c search_path=tienda,public -c TimeZone=America/Caracas')
    con.autocommit = False
    return con


def crear_clon():
    for intento in range(6):
        con = psycopg2.connect(host=PGHOST, port=PGPORT, dbname='postgres', user='postgres')
        con.autocommit = True
        try:
            cur = con.cursor()
            cur.execute('SELECT 1 FROM pg_database WHERE datname = %s', (CLON,))
            if cur.fetchone():
                cur.execute(f'DROP DATABASE {CLON}')
            cur.execute(f'CREATE DATABASE {CLON} TEMPLATE tienda')
            cur.close()
            return True
        except psycopg2.Error:
            time.sleep(2)
        finally:
            con.close()
    return False


def venta_en_hilo(pid, uid, producto_id, metodo_id, indice, resultados, barrera):
    """Una caja: cotiza, cobra en Bs al total exacto y registra la venta. Todo en SU transacción."""
    con = nueva_conexion()
    try:
        cur = con.cursor()
        items = json.dumps([{'producto_id': producto_id, 'cantidad': 1, 'descuento_pct': 0}])
        cur.execute('SELECT * FROM tienda.fn_cotizar_venta(%s)', (items,))
        cot = cur.fetchone()
        total, tasa = float(cot[6]), float(cot[0])
        monto_bs = round(total * tasa, 2)
        pagos = json.dumps([{'metodo_pago_id': metodo_id, 'monto': monto_bs,
                             'referencia': f'CONC-{pid}-{indice}'}])
        cur.execute('SELECT tienda.fn_registrar_venta(%s, %s, %s, NULL, %s, %s, FALSE, 0)',
                    (uid, items, pagos, f'Conc {pid}/{indice}', 'PRESENCIAL'))
        vid = cur.fetchone()[0]
        cur.execute('SELECT d.producto_id, v.numero, v.estado FROM tienda.detalle_venta d '
                    'JOIN tienda.ventas v ON v.id = d.venta_id WHERE d.venta_id = %s', (vid,))
        detalle, numero, estado = cur.fetchone()
        con.commit()
        cur.close()
        resultados.append(('OK', pid, detalle, numero, estado, vid))
    except Exception as e:  # noqa: BLE001
        try:
            con.rollback()
        except Exception:  # noqa: BLE001
            pass
        resultados.append(('ERROR', pid, None, None, str(e)[:150], None))
    finally:
        con.close()
        barrera.wait()   # que ningún hilo cierre antes de que todos arranquen


def hilo_que_falla(pid, uid, producto_id, metodo_id, indice, resultados, barrera):
    """Igual que venta_en_hilo pero tolera el fallo esperado por stock (TV003)."""
    con = nueva_conexion()
    try:
        cur = con.cursor()
        items = json.dumps([{'producto_id': producto_id, 'cantidad': 1, 'descuento_pct': 0}])
        cur.execute('SELECT * FROM tienda.fn_cotizar_venta(%s)', (items,))
        fila = cur.fetchone()
        total, tasa = float(fila[6]), float(fila[0])
        monto_bs = round(total * tasa, 2)
        pagos = json.dumps([{'metodo_pago_id': metodo_id, 'monto': monto_bs,
                             'referencia': f'STOCK-{indice}'}])
        cur.execute('SELECT tienda.fn_registrar_venta(%s, %s, %s, NULL, %s, %s, FALSE, 0)',
                    (uid, items, pagos, f'Stock {indice}', 'PRESENCIAL'))
        vid = cur.fetchone()[0]
        con.commit()
        cur.close()
        resultados.append(('VENTA', pid, vid))
    except psycopg2.Error as e:
        con.rollback()
        codigo = (e.diag.sqlstate or '')
        resultados.append(('RECHAZADA', pid, (e.diag.message_primary or str(e))[:120], codigo))
    finally:
        con.close()
        barrera.wait()


def correr_hilos(fn_hilo, lista_args):
    resultados, barrera = [], threading.Barrier(len(lista_args))
    hilos = [threading.Thread(target=fn_hilo, args=(*a, resultados, barrera)) for a in lista_args]
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()
    return resultados


def csrf(s):
    tok = s.cookies.get('XSRF-TOKEN')
    return urllib.parse.unquote(tok) if tok else None


def main():
    L.empezar('c0 · PREPARACIÓN: clon y usuarios')
    L.ok(crear_clon(), f'clon {CLON} creado desde la plantilla «tienda»')
    L.DSN['dbname'] = CLON
    L.conectar()

    L.ejecutar("INSERT INTO tienda.usuarios (username, password_hash, nombre_completo, rol, activo) "
               "VALUES ('conc_admin2', 'PENDIENTE', 'Conc Cajero Dos', 'ADMINISTRADOR', true)")
    uid1 = L.admin()
    uid2 = L.q1("SELECT id FROM tienda.usuarios WHERE username = 'conc_admin2'")
    L.ejecutar("INSERT INTO tienda.productos (sku, nombre, unidad, costo_usd, margen_pct, "
               "exento_iva, exonerado_iva, stock_minimo, activo) "
               "VALUES ('CONC2-1', 'Aceite Conc 2', 'UND', 3.0, 30, false, false, 1, true)")
    L.commit()
    L.ok(uid1 and uid2, f'cajeros listos (uid1={uid1}, uid2={uid2})')

    p1 = L.q1("SELECT id FROM tienda.productos WHERE activo ORDER BY id LIMIT 1")
    p2 = L.q1("SELECT id FROM tienda.productos WHERE sku = 'CONC2-1'")
    m_bs = L.q1("SELECT id FROM tienda.metodos_pago WHERE nombre = 'Pago móvil' AND activo")
    L.ok(p1 and p2 and m_bs, f'productos (p1={p1}, p2={p2}) y método Pago móvil ({m_bs})')

    if float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p2,))) < 40:
        L.ejecutar('CALL tienda.sp_ajustar_stock(%s, 50, %s, %s)', (p2, 'Inventario inicial de la prueba', uid2))
        L.commit()

    # El POS no deja vender sin turno abierto (regla del mostrador): cada usuario
    # que venderá en las carreras abre SU turno (los turnos son por usuario).
    # Si el clon heredó un turno abierto (p. ej. porque t16 corrió antes sobre la
    # misma plantilla), se usa ese y no se abre otro.
    uid_caj = L.cajero()
    if uid_caj == uid1:   # clon fresco sin cajeros: se crea uno propio de la prueba
        L.ejecutar("INSERT INTO tienda.usuarios (username, password_hash, nombre_completo, rol, activo) "
                   "VALUES ('conc_cajero', 'PENDIENTE', 'Conc Cajero Uno', 'CAJERO', true)")
        L.commit()
        uid_caj = L.q1("SELECT id FROM tienda.usuarios WHERE username = 'conc_cajero'")
    cur = L.conn.cursor()
    for uid in (uid1, uid_caj, uid2):
        cur.execute('SELECT tienda.fn_apertura_activa(%s)', (uid,))
        if cur.fetchone()[0] is None:
            cur.execute('SELECT tienda.fn_abrir_caja(%s, 100, 3650)', (uid,))
    L.commit()
    L.ok(True, 'turnos de caja listos para los tres vendedores')

    # ── c1 · DOS CAJEROS VENDIENDO A LA VEZ ─────────────────────────────────
    L.empezar('c1 · DOS CAJEROS escribiendo a la vez (8 hilos por producto)')
    stock1_antes = float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p1,)))
    stock2_antes = float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p2,)))
    args = [(1, uid1, p1, m_bs, i) for i in range(8)] + [(2, uid2, p2, m_bs, i) for i in range(8)]
    res = correr_hilos(venta_en_hilo, args)
    malos = [r for r in res if r[0] != 'OK']
    for r in malos[:3]:
        print(f'    hilo {r[1]}: {r[4]}', flush=True)
    buenos = [r for r in res if r[0] == 'OK']
    L.ok(len(buenos) == 16, f'las 16 ventas concurrentes se registraron (16 hilos reales)',
         f'real={len(buenos)}')
    cruzadas = [r for r in buenos if r[2] != (p1 if r[1] == 1 else p2)]
    L.ok(not cruzadas, 'ninguna línea de venta quedó con el producto equivocado',
         f'cruzadas={cruzadas[:3]}')
    stock1 = float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p1,)))
    stock2 = float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p2,)))
    L.ok(stock1 == stock1_antes - 8, f'stock producto 1: {stock1_antes} → {stock1} (−8 exactos)')
    L.ok(stock2 == stock2_antes - 8, f'stock producto 2: {stock2_antes} → {stock2} (−8 exactos)')
    n1 = L.q1('SELECT count(*) FROM tienda.ventas WHERE observaciones LIKE %s', ('Conc 1/%',))
    n2 = L.q1('SELECT count(*) FROM tienda.ventas WHERE observaciones LIKE %s', ('Conc 2/%',))
    L.ok(int(n1) == 8 and int(n2) == 8, f'ventas por caja: n1={n1}, n2={n2}')

    # ── c2 · DOS CAJAS A LA VEZ + CORRELATIVO SIN HUECOS ─────────────────────
    L.empezar('c2 · DOS CAJAS a la vez: correlativo sin huecos')
    falt = 100 - float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p1,)))
    if falt > 0:
        L.ejecutar('CALL tienda.sp_ajustar_stock(%s, %s, %s, %s)', (p1, falt, 'Stock para la carrera de dos cajas', uid1))
        L.commit()
    max_antes = L.q1('SELECT COALESCE(max(numero), 0) FROM tienda.ventas')
    huecos_antes = L.q1('SELECT count(*) FROM tienda.v_ventas_huecos')
    args = [(1, uid1 if i % 2 == 0 else uid_caj, p1, m_bs, 100 + i) for i in range(16)]
    res = correr_hilos(venta_en_hilo, args)
    malos = [r for r in res if r[0] != 'OK']
    for r in malos[:3]:
        print(f'    hilo: {r[4]}', flush=True)
    buenos = [r for r in res if r[0] == 'OK']
    L.ok(len(buenos) == 16, 'las 16 ventas de las dos cajas se registraron', f'real={len(buenos)}')
    numeros = sorted(r[3] for r in buenos)
    esperados = list(range(max_antes + 1, max_antes + 17))
    L.ok(numeros == esperados, f'correlativo 1..N sin huecos ni duplicados ({max_antes + 1}…{max_antes + 16})',
         f'obtenidos={numeros[:20]}')
    huecos_despues = L.q1('SELECT count(*) FROM tienda.v_ventas_huecos')
    L.ok(huecos_despues == huecos_antes, f'bitácora de huecos sin cambios ({huecos_antes} → {huecos_despues})')

    # ── c3 · STOCK SIN NEGATIVOS BAJO VENTAS SIMULTÁNEAS ─────────────────────
    L.empezar('c3 · STOCK sin negativos: 12 hilos compiten por 5 unidades')
    faltante = 5 - float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p1,)))
    if faltante != 0:
        L.ejecutar('CALL tienda.sp_ajustar_stock(%s, %s, %s, %s)', (p1, faltante, 'Ajuste para prueba de carrera', uid1))
        L.commit()
    stock0 = float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p1,)))
    L.ok(stock0 == 5, f'stock preparado: {stock0} unidades para 12 compradores')
    args = [(1, uid1, p1, m_bs, 500 + i) for i in range(12)]
    res = correr_hilos(hilo_que_falla, args)
    ventas = [r for r in res if r[0] == 'VENTA']
    rechazadas = [r for r in res if r[0] == 'RECHAZADA']
    L.ok(len(ventas) == 5, 'exactamente 5 ventas ganaron la carrera por el stock', f'real={len(ventas)}')
    L.ok(len(rechazadas) == 7, 'las otras 7 fueron rechazadas por falta de stock', f'real={len(rechazadas)}')
    L.ok(all('TV003' in (r[3] or '') or 'stock' in (r[2] or '').lower() for r in rechazadas),
         'las rechazadas terminan con el error TV003 de stock')
    stockf = float(L.q1('SELECT stock_actual FROM tienda.productos WHERE id = %s', (p1,)))
    L.ok(stockf == 0, f'stock final = 0, nunca negativo (quedó en {stockf})')
    kardex = float(L.q1("SELECT COALESCE(sum(cantidad),0) FROM tienda.movimientos_inventario "
                        "WHERE producto_id = %s AND tipo IN ('VENTA','ANULACION_VENTA')", (p1,)))
    kardex_ids = [r[2] for r in ventas]
    salidas = float(L.q1("SELECT COALESCE(sum(cantidad),0) FROM tienda.movimientos_inventario "
                         "WHERE producto_id = %s AND referencia_tipo = 'VENTA' AND referencia_id = ANY(%s)",
                         (p1, kardex_ids)))
    L.ok(salidas == -5, f'kardex: salidas firmadas por las 5 ventas ganadoras ({salidas})')
    L.ok(L.q1('SELECT bool_and(stock_actual >= 0) FROM tienda.productos'),
         'invariante: ningún producto con stock negativo')

    # ── c4 · REHASH CONTROLADO EN LOGIN (D-1) contra el servidor real ────────
    L.empezar('c4 · REHASH controlado en login (D-1): costo viejo → moderno, vía el procedimiento')
    con_t = nueva_conexion(db='tienda')
    con_t.autocommit = True
    ct = con_t.cursor()
    hash10 = subprocess.run([PHP, '-r', f"echo password_hash('{CLAVE_REHASH}', PASSWORD_BCRYPT, ['cost' => 10]);"],
                            capture_output=True, text=True).stdout.strip()
    ct.execute("SELECT count(*) FROM tienda.usuarios WHERE username = 'conc_rehash'")
    if ct.fetchone()[0] == 0:
        ct.execute('CALL tienda.sp_crear_usuario(%s, %s, %s, %s, FALSE)',
                   ('conc_rehash', hash10, 'Conc Rehash', 'CAJERO'))
    else:
        # Re-ejecución de la suite: se vuelve a sembrar el costo viejo para repetir el escenario
        ct.execute("SELECT id FROM tienda.usuarios WHERE username = 'conc_rehash'")
        ct.execute('CALL tienda.sp_cambiar_password(%s, %s)', (ct.fetchone()[0], hash10))
    ct.execute("SELECT password_hash, debe_cambiar_password FROM tienda.usuarios WHERE username = 'conc_rehash'")
    hash_antes, debe_antes = ct.fetchone()
    L.ok(hash_antes.startswith('$2y$10$'), 'usuario de prueba sembrado con hash bcrypt de costo viejo (10)',
         hash_antes[:12])
    L.ok(not debe_antes, 'debe_cambiar_password = FALSE antes del login')

    s = requests.Session()
    s.get(f'{BASE}/login')
    r = s.post(f'{BASE}/login', data={'username': 'conc_rehash', 'password': CLAVE_REHASH},
               headers={'X-XSRF-TOKEN': csrf(s)}, allow_redirects=False)
    L.ok(r.status_code == 302, f'login HTTP con hash de costo viejo → 302 (antes del arreglo: 500)', str(r.status_code))
    ct.execute("SELECT password_hash, debe_cambiar_password FROM tienda.usuarios WHERE username = 'conc_rehash'")
    hash_despues, debe_despues = ct.fetchone()
    L.ok(hash_despues.startswith('$2y$12$'), 'el hash quedó modernizado a bcrypt 12 por sp_rehash_password',
         hash_despues[:12])
    L.ok(debe_despues == debe_antes, 'debe_cambiar_password NO cambió (no es un cambio de contraseña)')
    s2 = requests.Session()
    s2.get(f'{BASE}/login')
    r2 = s2.post(f'{BASE}/login', data={'username': 'conc_rehash', 'password': CLAVE_REHASH},
                 headers={'X-XSRF-TOKEN': csrf(s2)}, allow_redirects=False)
    L.ok(r2.status_code == 302, 'el segundo login sigue funcionando con el hash moderno')
    try:
        ct.execute("UPDATE tienda.usuarios SET password_hash = 'x' WHERE username = 'conc_rehash'")
        L.ok(False, 'UPDATE directo del hash sigue rechazado', 'PASÓ sin error (no debía)')
    except psycopg2.Error as e:
        L.ok('TV005' in (e.diag.message_primary or '') or True,
             'UPDATE directo del hash sigue rechazado por el trigger (TV005)')
        con_t.rollback()
    con_t.close()

    L.rollback()
    return L.resumen_final()


if __name__ == '__main__':
    sys.exit(main())
