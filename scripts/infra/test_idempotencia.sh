#!/bin/bash
# =============================================================================
#  test_idempotencia.sh · Regresión de idempotencia de los SQL de database/sql.
#
#  POR QUÉ EXISTE: re-aplicar 02/10 u otro archivo sobre una base ya instalada
#  borró CHECKs y pisó validadores (bugs #3/#4 de la conversión tienda única).
#  Este test reinstala desde cero y luego re-aplica CADA archivo una segunda
#  vez: si alguno rompe, o si un CHECK/catálogo queda duplicado o borrado,
#  falla duro. En CI hace que el bug #3/#4 no pueda volver.
#
#  Uso: bash scripts/infra/test_idempotencia.sh   (env: PSQL, PGHOST, PGPORT)
# =============================================================================
set -e
cd "$(dirname "$0")/../.."          # raíz del proyecto (motos2026)

PSQL=${PSQL:-$(command -v psql || echo psql)}
HOST=${PGHOST:-/tmp}
PORT=${PGPORT:-5433}
DB=tienda_idem

q() { "$PSQL" -h "$HOST" -p "$PORT" -U postgres -d "$DB" -v ON_ERROR_STOP=1 -tA -c "$1"; }
qq() { "$PSQL" -h "$HOST" -p "$PORT" -U postgres -d "$DB" -v ON_ERROR_STOP=1 -tA -c "SET search_path=tienda,public; $1" | tail -n 1; }
drop() { "$PSQL" -h "$HOST" -p "$PORT" -U postgres -d postgres -tA -c "DROP DATABASE IF EXISTS $DB" >/dev/null; }
trap drop EXIT

echo "── 1 · instalación completa (TODOS los archivos en orden, como setup_db.py)"
drop
"$PSQL" -h "$HOST" -p "$PORT" -U postgres -d postgres -tA -c "CREATE DATABASE $DB" >/dev/null
for f in database/sql/0[1-9]*.sql database/sql/[1-3][0-9]_*.sql; do
    [ -e "$f" ] || continue
    "$PSQL" -h "$HOST" -p "$PORT" -U postgres -d "$DB" -v ON_ERROR_STOP=1 -f "$f" >/dev/null || {
        echo "FALLO aplicando (1.ª vez) $f"; exit 1; }
done

MARCAS_1=$(q "SELECT count(*) FROM tienda.marcas")
PARAMS_1=$(q "SELECT count(*) FROM tienda.parametros_fiscales WHERE codigo='IVA_GENERAL'")

echo "── 2 · re-aplicar CADA archivo una segunda vez (debe ser idempotente)"
FALLOS=""
for f in database/sql/0[1-9]*.sql database/sql/[1-3][0-9]_*.sql; do
    [ -e "$f" ] || continue
    "$PSQL" -h "$HOST" -p "$PORT" -U postgres -d "$DB" -v ON_ERROR_STOP=1 -f "$f" >/dev/null 2>&1 || FALLOS="$FALLOS\n  $f"
done
[ -z "$FALLOS" ] || { printf "FALLO re-aplicando:%b\n" "$FALLOS"; exit 1; }

echo "── 3 · verificación semántica"
# a) los procedimientos que vivían en el (borrado) 41_endurecimiento_seguridad.sql y en los demás
#    borrados SaaS deben existir una sola vez (bugs #2/#3/#4 de la conversión)
N=$(q "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname='tienda' AND p.proname IN
        ('sp_cancelar_alicuota','sp_alta_moneda','sp_moneda_activa','sp_cambiar_alicuota','sp_registrar_tasa')")
[ "$N" = "5" ] || { echo "FALLO: se esperaban 5 sp_ del endurecimiento borrado y hay $N"; exit 1; }

# b) el CHECK de tipos de asiento (asientos_origen_tipo_check) sigue íntegro: una sola constraint con
#    RETENCION_ISLR sobre la tabla, y citada una sola vez (no pisada ni duplicada por re-aplicar el 10)
N=$(q "SELECT count(*) FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
        WHERE n.nspname='tienda' AND t.relname='asientos' AND pg_get_constraintdef(c.oid) LIKE '%RETENCION_ISLR%'")
[ "$N" = "1" ] || { echo "FALLO: asientos debe conservar una sola constraint con RETENCION_ISLR y hay $N"; exit 1; }

# c) la vista del libro de ventas (vivía en el borrado 37_libro_ventas_negocio.sql) existe
q "SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname='tienda' AND c.relname='v_libro_ventas' AND c.relkind IN ('v','m')" | grep -q 1 \
   || { echo "FALLO: falta la vista v_libro_ventas"; exit 1; }

# d) catálogos sin duplicados ni filas perdidas tras la doble aplicación
MARCAS_2=$(q "SELECT count(*) FROM tienda.marcas")
PARAMS_2=$(q "SELECT count(*) FROM tienda.parametros_fiscales WHERE codigo='IVA_GENERAL'")
[ "$MARCAS_1" = "$MARCAS_2" ] || { echo "FALLO: marcas cambió con la re-aplicación ($MARCAS_1 → $MARCAS_2)"; exit 1; }
[ "$PARAMS_1" = "1" ] && [ "$PARAMS_2" = "1" ] || { echo "FALLO: IVA_GENERAL duplicado ($PARAMS_1 → $PARAMS_2)"; exit 1; }

# e) UT real sembrada (histórico verificado) y vigente = 43,00
N=$(qq "SELECT count(*) FROM islr_ut")
V=$(qq "SELECT fn_islr_ut(fn_hoy())")
[ "$N" = "2" ] || { echo "FALLO: islr_ut debe tener el histórico real (2 filas) y tiene $N"; exit 1; }
[ "$V" = "43.0000" ] || { echo "FALLO: la UT vigente debe ser 43,00 y es $V"; exit 1; }

echo "IDEMPOTENCIA OK · re-aplicación limpia, 5 sp_ presentes, CHECK íntegro, vistas y UT correctas"
