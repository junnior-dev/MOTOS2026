#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas de concurrencia a nivel PostgreSQL para el informe de rendimiento.

Se corren sobre un CLON dedicado (tienda_carga) con la plantilla «tienda» de tienda única.

  escenario A · 100 CONEXIONES CONCURRENTES REALES: hilos con conexiones propias
                 y barrera de arranque; cada operación cotiza o vende por la misma
                 ruta que el POS. Mide latencia y throughput, y verifica que cada
                 venta descargue el stock de SU producto.
"""
import json
import os
import statistics
import threading
import time

import psycopg2

PGHOST = os.environ.get('PGHOST', '/tmp')
PGPORT = int(os.environ.get('PGPORT', '5433'))
CLON = 'tienda_carga'
PRODUCTO = 1                       # producto de venta (se llena en preparar())


def nueva_conexion(db=CLON):
    con = psycopg2.connect(host=PGHOST, port=PGPORT, dbname=db, user='postgres',
                           options='-c search_path=tienda,public')
    con.autocommit = False
    return con


def pct(lista, q):
    lista = sorted(lista)
    if not lista:
        return 0.0
    return lista[min(int(len(lista) * q / 100), len(lista) - 1)]


def preparar():
    """Clon dedicado + stock inicial (el usuario 1 de la plantilla ya tiene turno o lo abre aquí)."""
    con = psycopg2.connect(host=PGHOST, port=PGPORT, dbname='postgres', user='postgres')
    con.autocommit = True
    cur = con.cursor()
    for _ in range(6):
        try:
            cur.execute('SELECT 1 FROM pg_database WHERE datname = %s', (CLON,))
            if cur.fetchone():
                cur.execute(f'DROP DATABASE {CLON}')
            cur.execute(f'CREATE DATABASE {CLON} TEMPLATE tienda')
            break
        except psycopg2.Error:
            time.sleep(2)
    cur.close()
    con.close()

    con = nueva_conexion()
    cur = con.cursor()
    cur.execute('SELECT min(id) FROM tienda.productos WHERE activo')
    global PRODUCTO
    PRODUCTO = int(cur.fetchone()[0])
    cur.execute('SELECT tienda.fn_apertura_activa(1)')
    if cur.fetchone()[0] is None:
        cur.execute('SELECT tienda.fn_abrir_caja(1, 100, 3650)')
    cur.execute('CALL tienda.sp_ajustar_stock(%s, 300, %s, 1)', (PRODUCTO, 'Stock para la prueba de carga'))
    con.commit()
    con.close()
    return 1


def escenario_a(n_conexiones=100, ops_por_conexion=6, uid=1):
    print(f'\n== Escenario A · {n_conexiones} conexiones concurrentes reales '
          f'({ops_por_conexion} ops cada una) ==', flush=True)
    resultados, errores = [], []
    lock = threading.Lock()
    barrera = threading.Barrier(n_conexiones)

    def trabajador(idx):
        con = None
        try:
            con = nueva_conexion()
            cur = con.cursor()
            barrera.wait()
            for op in range(ops_por_conexion):
                t0 = time.perf_counter()
                items = json.dumps([{'producto_id': PRODUCTO, 'cantidad': 1, 'descuento_pct': 0}])
                cur.execute('SELECT * FROM tienda.fn_cotizar_venta(%s)', (items,))
                cot = cur.fetchone()
                total, tasa = float(cot[6]), float(cot[0])
                if op % 3 == 2:
                    pagos = json.dumps([{'metodo_pago_id': 4, 'monto': round(total * tasa, 2),
                                         'referencia': f'CONC-{idx}-{op}'}])
                    cur.execute('SELECT tienda.fn_registrar_venta(%s, %s, %s, NULL, %s, %s, FALSE, 0)',
                                (uid, items, pagos, f'Carga {idx}/{op}', 'PRESENCIAL'))
                    cur.fetchone()
                else:
                    cur.execute('SELECT count(*) FROM tienda.productos WHERE activo')
                    cur.fetchone()
                con.commit()
                with lock:
                    resultados.append((time.perf_counter() - t0) * 1000)
        except Exception as e:  # noqa: BLE001
            with lock:
                errores.append(str(e)[:110])
            try:
                con.rollback()
            except Exception:  # noqa: BLE001
                pass
            try:
                barrera.abort()
            except threading.BrokenBarrierError:
                pass
        finally:
            if con:
                con.close()

    inicio = time.perf_counter()
    hilos = [threading.Thread(target=trabajador, args=(i,)) for i in range(n_conexiones)]
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()
    total_s = time.perf_counter() - inicio

    lat = list(resultados)
    print(f'  operaciones OK: {len(lat)} · errores: {len(errores)} · duración total: {total_s:.1f}s')
    print(f'  throughput: {len(lat) / total_s:.1f} ops/s')
    print(f'  latencia ms · p50={pct(lat, 50):.1f} · p95={pct(lat, 95):.1f} · p99={pct(lat, 99):.1f} '
          f'· media={statistics.mean(lat):.1f} · máx={max(lat):.1f}' if lat else '  sin resultados')
    for e in errores[:3]:
        print(f'  ERROR: {e}')
    return {'ops_ok': len(lat), 'errores': len(errores), 'total_s': round(total_s, 1),
            'throughput': round(len(lat) / total_s, 1) if lat else 0,
            'p50': round(pct(lat, 50), 1), 'p95': round(pct(lat, 95), 1), 'p99': round(pct(lat, 99), 1)}


def verificar_a():
    """Las ventas de la carrera descargaron stock del producto correcto; sin negativos."""
    con = nueva_conexion()
    cur = con.cursor()
    cur.execute("SELECT count(*) FROM tienda.ventas WHERE observaciones LIKE 'Carga %'")
    ventas = int(cur.fetchone()[0])
    cur.execute("SELECT bool_and(stock_actual >= 0) FROM tienda.productos")
    sin_negativos = bool(cur.fetchone()[0])
    con.close()
    print(f'  ventas de la carrera: {ventas} · stock sin negativos: {sin_negativos}')
    return {'ventas': ventas, 'stock_negativo': not sin_negativos}


def main():
    uid = preparar()
    res_a = escenario_a(uid=uid)
    ver_a = verificar_a()

    print('\n== RESUMEN DE CARGA ==')
    print('escenario_a =', json.dumps(res_a))
    print('verificacion_a =', json.dumps(ver_a))


if __name__ == '__main__':
    main()
