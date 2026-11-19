#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Orquestador de las suites de pruebas del proyecto (DB-level):
e2e (t01..t15) y fiscal (f01..f17). Reinicia cada clon desde TEMPLATE tienda
antes de cada suite. Uso: python3 run_suites.py [salida.log]
"""
import os
import shutil
import subprocess
import sys
import time
from pathlib import Path

import psycopg2

BASE = Path(__file__).resolve().parents[2] / 'tests'
PY = sys.executable
# psql: primero el del entorno, luego el del PATH y como última alternativa los
# binarios del stack del sandbox (en CI el del PATH siempre está).
PGSQL = os.environ.get('PSQL') or shutil.which('psql') or 'psql'

RUNNERS = [
    ('e2e/run_all.py',        'tienda_e2e',   'OPERATIVAS (t01..t15)'),
    ('fiscal/run_fiscal.py',  'tienda_fiscal','FISCALES (f01..f17)'),
]


def reset_clon(nombre: str, desde_template: bool) -> bool:
    """Reinicia un clon de pruebas (con reintentos por locks). Usa psycopg2:
    funciona igual con o sin el binario psql en el PATH."""
    host = os.environ.get('PGHOST', '/tmp')
    puerto = int(os.environ.get('PGPORT', '5433'))
    for _ in range(4):
        try:
            con = psycopg2.connect(host=host, port=puerto, dbname='postgres', user='postgres', connect_timeout=5)
            con.autocommit = True
            cur = con.cursor()
            cur.execute(f'DROP DATABASE IF EXISTS {nombre}')
            if desde_template:
                cur.execute(f'CREATE DATABASE {nombre} TEMPLATE tienda')
            cur.close()
            con.close()
            return True
        except psycopg2.Error:
            time.sleep(2)
    print('  ERROR reset: no se pudo reiniciar el clon', nombre)
    return False


def main() -> int:
    salida = Path(sys.argv[1]) if len(sys.argv) > 1 else Path('/tmp/pruebas_salida.log')
    salida.write_text('')
    import os
    import re
    env = dict(os.environ)
    env['PSQL'] = PGSQL
    env['PGHOST_PRUEBAS'] = os.environ.get('PGHOST', '/tmp')
    env['PGPORT_PRUEBAS'] = os.environ.get('PGPORT', '5433')
    total_ok = total_fail = 0
    fallidas = []
    for runner, clon, titulo in RUNNERS:
        print(f'━━━ {titulo} · clon {clon}')
        # cada clon se recrea desde TEMPLATE tienda
        if not reset_clon(clon, True):
            fallidas.append(titulo)
            continue
        r = subprocess.run([PY, str(BASE / runner)], capture_output=True, text=True, cwd=str(BASE / runner.split('/')[0]), env=env)
        out = (r.stdout + ('\n[stderr]\n' + r.stderr if r.stderr.strip() else ''))
        with open(salida, 'a', encoding='utf-8') as fh:
            fh.write('=' * 70 + f'\n{titulo}\n' + '=' * 70 + '\n' + out[-20000:] + '\n')
        m = re.search(r'TOTAL\s+(\d+)\s+OK\s+(\d+)\s+FAIL', out)
        if m:
            ok, fail = int(m.group(1)), int(m.group(2))
            total_ok += ok
            total_fail += fail
            print(f'  RESULTADO: {ok} OK / {fail} FAIL')
        else:
            print(f'  (sin línea TOTAL · salida en {salida.name})')
            fallidas.append(titulo + ' (sin resumen)')
    print(f'\n=== TOTALES: {total_ok} OK / {total_fail} FAIL ===')
    if fallidas:
        print('Suites con problemas:', fallidas)
    return 0 if total_fail == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
