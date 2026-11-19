# -*- coding: utf-8 -*-
"""Orquestador de las pruebas fiscales: reinicia el clon «tienda_fiscal» y corre f01→f17.
Uso: python3 run_fiscal.py"""
import io
import os
import subprocess
import sys
import traceback

import f_lib as F
import e2e_lib as L

SUITES = [
    ('f01_iva_alicuota', 'f01 · CRÍTICA IVA: cambio de alícuota (congelación)'),
    ('f02_islr_sustraendo', 'f02 · CRÍTICA ISLR: sustraendo, natural/jurídica, UT'),
    ('f03_igtf', 'f03 · CRÍTICA IGTF: cobro, reversión y declaración'),
    ('f04_entero_islr', 'f04 · CRÍTICA ISLR entero: período completo'),
    ('f05_libro_ventas', 'f05 · CRÍTICA Libro de ventas: tipos y anuladas'),
    ('f06_calendario', 'f06 · CRÍTICA Calendario fiscal: RIF y régimen'),
    ('f07_cierre_ejercicio', 'f07 · CRÍTICA Cierre de ejercicio'),
    ('f08_ajuste_cambiario', 'f08 · CRÍTICA Ajuste cambiario del inventario'),
    ('f09_notas_cxc', 'f09 · ALTA Notas de crédito sobre venta a crédito'),
    ('f10_retenciones_iva', 'f10 · ALTA Retenciones de IVA y entero quincenal'),
    ('f11_conciliacion_seniat', 'f11 · ALTA Conciliación fiscal SENIAT'),
    ('f12_libro_compras', 'f12 · MEDIA Libro de compras'),
    ('f13_cierre_periodo', 'f13 · MEDIA Cierre de período'),
    ('f14_huecos', 'f14 · MEDIA Numeración con huecos'),
    ('f15_asientos_auto', 'f15 · MEDIA Asientos automáticos'),
    ('f16_invariantes', 'f16 · INVARIANTES GLOBALES (capa 2)'),
    ('f17_estados_imposibles', 'f17 · ESTADOS IMPOSIBLES (capa 3)'),
    ('f18_islr_anual', 'f18 · CRÍTICA ISLR anual: tarifas oficiales del art. 53 y revisión antes de liquidar'),
]


def reset_clon():
    host = os.environ.get('PGHOST_PRUEBAS', '/tmp')
    puerto = int(os.environ.get('PGPORT_PRUEBAS', '5433'))
    # Con psycopg2 (no exige el binario psql en el PATH; mismo resultado).
    import psycopg2
    con = psycopg2.connect(host=host, port=puerto, dbname='postgres', user='postgres', connect_timeout=5)
    con.autocommit = True
    cur = con.cursor()
    cur.execute('DROP DATABASE IF EXISTS tienda_fiscal')
    cur.execute('CREATE DATABASE tienda_fiscal TEMPLATE tienda')
    cur.close()
    con.close()


def main():
    reset_clon()
    F.conectar_fiscal()
    fallos_suites = []
    for modulo, titulo in SUITES:
        try:
            mod = __import__(modulo)
            mod.main()
        except Exception as exc:  # noqa: BLE001
            L.rollback()
            L.CONTEO.setdefault(titulo, [0, 0])
            L.CONTEO[titulo][1] += 1
            print(f"  FAIL suite interrumpida: {type(exc).__name__}: {exc}", flush=True)
            traceback.print_exc(file=sys.stderr)
            fallos_suites.append((modulo, str(exc)))
    total_fail = L.resumen_final()
    if fallos_suites:
        print('\nSUITES INTERRUMPIDAS POR ERROR NO MANEJADO:')
        for m, e in fallos_suites:
            print(f'  {m}: {e[:160]}')
    L.conn.close()
    sys.exit(1 if total_fail else 0)


if __name__ == '__main__':
    main()
