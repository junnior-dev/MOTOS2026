#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
"""Valida la TRAZABILIDAD bidireccional: cada requisito con ≥1 prueba que lo
respalda y cada suite/bloque referenciado por ≥1 requisito (sin huérfanos)."""
import json
from pathlib import Path

RAIZ = Path(__file__).resolve().parent
REQUISITOS = {
    "Instalación y asistente": ["t01", "f06", "setup_db"],
    "Catálogo y compatibilidad": ["t02", "t16"],
    "Usuarios, roles y sesión de la app": ["t03", "pest_auth"],
    "Presentaciones caja/pieza": ["t01", "t02"],
    "Combos/kits": ["t06", "t16", "t18"],
    "Encargos": ["t09", "t10", "t16"],
    "Compras con CxP": ["t04", "t18"],
    "Conteo físico y kardex": ["t05", "t16"],
    "POS apertura y turnos": ["t06", "t16", "t18"],
    "POS venta contado": ["t06", "t18"],
    "POS venta a crédito": ["t06", "t07", "t18"],
    "Devoluciones y notas": ["t08", "f09", "t11"],
    "IGTF divisas": ["t06", "f03", "f16"],
    "Retenciones IVA y entero quincenal": ["f10", "f11"],
    "Alícuota IVA congelada": ["f01", "f16"],
    "Relación informativa XML de retenciones ISLR (Portal Fiscal)": ["t23"],
    "Libros fiscales": ["f05", "f12", "t16", "t18"],
    "ISLR retenciones y entero": ["f02", "f04", "t16"],
    "ISLR anual (declaración del ejercicio)": ["t11", "f04"],
    "Calendario fiscal": ["f06", "t16"],
    "Ajuste cambiario": ["f08"],
    "Contabilidad partida doble": ["t12", "f15", "f16"],
    "Cierre de caja X/Z": ["t13", "t16", "t18"],
    "Cierre contable y ejercicio": ["f07", "f13"],
    "Invariantes y estados imposibles": ["t15", "f16", "f17"],
    "Seguridad y límites hostiles": ["t14", "pest_auth"],
    "Correlativo y stock bajo carrera": ["t17", "t15", "f14"],
    "Páginas Inertia bien formadas": ["t16", "pest_paginas"],
    "UI sin errores de consola": ["t18"],
    "Rendimiento y latencia": ["k6"],
    "Concurrencia HTTP y DB masiva": ["k6", "carga_pg"],
    "Continuidad backup/restore/rollback": ["continuidad"],
    "Autorización por endpoint por rol": ["pest_auth"],
    "Validaciones de formulario": ["pest_valid", "t14"],
    "Dependencias y SAST": ["audit"],
    "Flujo de venta por la pila Laravel": ["pest_venta"],
}
# Prefijos de bloques que existen en resultados_pruebas.json (familia:id)
SUITES_VALIDAS = set()
res = json.load(open(RAIZ / '..' / 'resultados_pruebas.json', encoding='utf-8')) if (RAIZ / '..' / 'resultados_pruebas.json').exists() else {}
for fam in res.get('familias', []):
    for f in fam['filas']:
        SUITES_VALIDAS.add(f['id'])
for sid in ('t16', 't17', 't18'):
    if sid in res:
        SUITES_VALIDAS.add(sid)
# suites fuera del json (existencia en disco)
EXTRAS = {
    'k6': RAIZ.parent / 'scripts' / 'carga',
    'carga_pg': RAIZ.parent / 'scripts' / 'carga' / 'concurrencia_pg.py',
    'continuidad': RAIZ.parent / 'scripts' / 'infra' / 'continuidad.py',
    'setup_db': RAIZ.parent / 'scripts' / 'infra' / 'setup_db.py',
    'audit': RAIZ.parent / 'package.json',
}
DIRS = {
    'pest_paginas': RAIZ / 'Feature' / 'PaginasInertiaTest.php',
    'pest_auth': RAIZ / 'Feature' / 'AutorizacionEndpointTest.php',
    'pest_valid': RAIZ / 'Feature' / 'ValidacionesFormularioTest.php',
    'pest_venta': RAIZ / 'Feature' / 'FlujoVentaTest.php',
}
base_tests = RAIZ.parent / 'tests'

def existe(suite):
    if suite in SUITES_VALIDAS:
        return True
    if suite in EXTRAS:
        return EXTRAS[suite].exists()
    if suite in DIRS:
        return DIRS[suite].exists()
    # suites tXX/fXX/mXX/pXX: buscar el archivo de la suite en tests/
    patron = {'t': 'e2e', 'f': 'fiscal'}
    pref = suite[0]
    if pref in patron:
        d = base_tests / patron[pref]
        return d.exists() and any(d.glob(f'{suite}*.py'))
    return False

print('== TRAZABILIDAD requisito ↔ prueba ==')
huérfanas, muertas = [], []
for req, suites in sorted(REQUISITOS.items()):
    faltan = [s for s in suites if not existe(s)]
    if faltan:
        muertas.append((req, faltan))
        print(f'  FAIL {req}: pruebas inexistentes {faltan}')
    else:
        print(f'  OK   {req} ← {", ".join(suites)}')

# huérfanas: bloques del json que ningún requisito referencia
referenciadas = set()
for suites in REQUISITOS.values():
    referenciadas.update(suites)
for suite in sorted(SUITES_VALIDAS):
    if suite not in referenciadas:
        huérfanas.append(suite)
        print(f'  HUÉRFANA: la suite {suite} no respalda ningún requisito')

n = len(REQUISITOS)
print(f'\nRequisitos: {n} · sin respaldo: {len(muertas)} · suites huérfanas: {len(huérfanas)}')
print('RESULTADO: ' + ('TRAZABILIDAD COMPLETA Y SIN HUÉRFANOS' if not muertas and not huérfanas else 'REVISAR ARRIBA'))
sys.exit(0 if not muertas and not huérfanas else 1)
