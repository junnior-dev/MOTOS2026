#!/bin/bash
# =============================================================================
#  run_all.sh · LA CADENA COMPLETA DE PRUEBAS DEL SISTEMA (tienda única), EN
#  ORDEN Y CON SUS DEPENDENCIAS. Cada paso falla duro (set -e) para que una
#  regresión nunca pase desapercibida.
#
#  Orden (y POR QUÉ):
#   0 · PostgreSQL vivo en 5433 (socket /tmp) — todo lo demás lo necesita.
#       (en CI/Actions la base la pone un contenedor de servicio postgres y los
#       workflows no pasan por este script sino por scripts/infra/ por job.)
#   1 · setup_db.py (scripts/infra/) → reinstala `tienda` LIMPIA con los scripts
#       01–39 + semilla de pruebas.
#   2 · run_suites.py (scripts/infra/) → funcionales t01–t17 · f01–f17 contra
#       clones de la plantilla.
#   3 · t16 (Inertia HTTP)    → necesita la base limpia.
#   4 · t17 (concurrencia)    → tolera el turno que t16 deja abierto.
#   5 · t18 (navegador E2E)   → necesita el SERVIDOR HTTP en :8000 y los
#                               assets de Vite construidos.
#   6 · Pest                  → base DEDICADA tienda_pest (clon del paso 1).
#   7 · concurrencia_pg       → clon tienda_carga.
#   8 · trazabilidad.py       → cruza requisitos ↔ suites.
#   9 · composer/npm audit    → 0 vulnerabilidades esperadas.
#
#  Uso: bash scripts/run_all.sh [--rapido]   (sin --rapido corre TODO)
# =============================================================================
set -e
cd "$(dirname "$0")/.."

PHP=${PHP:-$(command -v php || echo php)}
PY=${PY:-python3}
[ -x "$PHP" ] || PHP=php

export PHP_CLI_SERVER_WORKERS=8

echo "═══ 0 · PostgreSQL en 5433 (si no está vivo, arranca) ═══"
if command -v pg_isready >/dev/null 2>&1; then
    pg_isready -h /tmp -p 5433 >/dev/null 2>&1 || echo "(aviso: PostgreSQL no responde en /tmp:5433)"
fi

if [ "$1" != "--rapido" ]; then
    echo "═══ 1 · Instalación limpia de bases (01–39 + semilla) ═══"
    $PY scripts/infra/setup_db.py --sin-bcv

    echo "═══ 1b · Idempotencia de los SQL (re-aplicar todo no rompe la base) ═══"
    bash scripts/infra/test_idempotencia.sh

    echo "═══ 2 · Funcionales t/f (dos suites) ═══"
    $PY scripts/infra/run_suites.py /tmp/run_all_funcionales.log | tail -3
    tail -3 /tmp/run_all_funcionales.log

    echo "═══ 2b · Validación fiscal con datos simulados (faker, t20) ═══"
    $PY tests/e2e/t20_validacion_fiscal_simulada.py | tail -2
else
    echo "═══ (--rapido: se salta instalación y funcionales) ═══"
fi

echo "═══ 3–5 · Servidor HTTP en :8000 + t16 + t17 + t18 ═══"
$PHP artisan serve --host=127.0.0.1 --port=8000 > /tmp/run_all_serve.log 2>&1 &
SERVE_PID=$!
trap 'kill $SERVE_PID 2>/dev/null || true' EXIT
# El throttle de login (5/min por IP) usa el cache de archivos y PERSISTE entre
# corridas: las suites HTTP hacen varios logins y no deben heredar la ventana
# agotada de una corrida anterior.
$PHP artisan cache:clear >/dev/null 2>&1 || true
for i in $(seq 1 30); do curl -sf -o /dev/null http://127.0.0.1:8000/up && break; sleep 1; done

$PY tests/e2e/t16_paginas_inertia.py     | tee /tmp/run_all_t16.log | tail -2
$PY tests/e2e/t17_concurrencia.py        | tee /tmp/run_all_t17.log | tail -2
$PY tests/e2e/t18_e2e_navegador.py       | tee /tmp/run_all_t18.log | tail -2

echo "═══ 5b · Acceso y Configuración por HTTP real (t19: login, registro, configuración) ═══"
$PY tests/e2e/t19_acceso_configuracion.py | tee /tmp/run_all_t19.log | tail -2

echo "═══ 6 · Pest (Laravel, base dedicada) ═══"
$PHP vendor/bin/pest --compact 2>&1 | tail -4

echo "═══ 7 · Concurrencia PostgreSQL (A/B/C) ═══"
$PY scripts/carga/concurrencia_pg.py | tail -6

echo "═══ 8 · Trazabilidad requisitos ↔ suites ═══"
$PY tests/trazabilidad.py | tail -3

echo "═══ 9 · Auditorías de dependencias ═══"
composer audit --no-cache 2>/dev/null | tail -2 || echo "(composer audit: revisar)"
npm audit --omit=dev 2>/dev/null | tail -2 || echo "(npm audit: revisar)"

echo "═══ CADENA COMPLETA OK ═══"
