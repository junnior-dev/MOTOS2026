#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Instalación completa de la base del sistema (tienda única) con psycopg2 (no hay psql necesario).
Uso: python3 setup_db.py [--solo-verificar]"""
import os
import sys
from pathlib import Path

import psycopg2

SQL = str(Path(__file__).resolve().parents[2] / 'database' / 'sql')
HOST = os.environ.get('PGHOST', '/tmp')
PORT = int(os.environ.get('PGPORT', '5433'))

TIENDA_FILES = (
    ['01_tablas.sql', '02_funciones_procedimientos.sql', '03_triggers.sql', '04_vistas.sql', '05_datos_iniciales.sql']
    + [f'{n:02d}_{n2}.sql' for n, n2 in
       [(6, 'busqueda_sin_tildes'), (7, 'cierres_caja'), (8, 'retenciones_iva'), (9, 'numeracion_fiscal'),
        (10, 'contabilidad'), (11, 'cierre_y_pagos'), (12, 'devolucion_notas'), (13, 'conciliaciones'),
        (14, 'aperturas_caja'), (15, 'cuentas_por_cobrar'), (16, 'correcciones_control'), (17, 'metodos_pago'),        (18, 'devolucion_en_caja'), (19, 'regimen_y_apertura'), (20, 'retenciones_islr'), (21, 'calendario_fiscal'),
        (22, 'calendario_parametrizable'), (23, 'unidades_piezas'), (24, 'encargos'), (25, 'combos'),
        (26, 'ordenes_compra'), (27, 'conteos_fisicos'), (28, 'analisis_rentabilidad'), (29, 'caja_desglose'),
        (30, 'calendario_ordinario')]]
    + ['14b_aperturas_multimoneda.sql', '14c_snapshot_cierre.sql']
    + ['36_islr_declaracion.sql', '38_presentaciones_venta.sql', '39_islr_asientos.sql', '40_islr_tarifas_modelo.sql', '41_tfhka_facturacion.sql', '42_notas_iva_efectivo.sql', '43_facturacion_modos.sql', '44_tour_onboarding.sql', '45_cola_fiscal.sql', '46_islr_xml_relacion.sql']
)

# Semilla de la plantilla de pruebas (negocio demo, admin con contraseña conocida por las suites)
SEED_TIENDA = r"""
SET search_path = tienda, public;
INSERT INTO tienda.tasas_cambio (moneda_origen, moneda_destino, tasa, fecha_valor, fuente, es_oficial_bcv)
VALUES ('USD', 'VES', 36.50, CURRENT_DATE, 'BCV', true)
ON CONFLICT (moneda_origen, moneda_destino, fecha_valor) DO NOTHING;
INSERT INTO tienda.tasas_cambio (moneda_origen, moneda_destino, tasa, fecha_valor, fuente, es_oficial_bcv)
VALUES ('EUR', 'VES', 1.0352, CURRENT_DATE, 'BCV', true)
ON CONFLICT (moneda_origen, moneda_destino, fecha_valor) DO NOTHING;
INSERT INTO tienda.tasas_cambio (moneda_origen, moneda_destino, tasa, fecha_valor, fuente, es_oficial_bcv)
VALUES ('COP', 'VES', 0.2280, CURRENT_DATE, 'BCV', true)
ON CONFLICT (moneda_origen, moneda_destino, fecha_valor) DO NOTHING;
-- Hash bcrypt (costo 12) de «PaginasTest2026»: la clave del admin que usan las
-- suites t16 (páginas Inertia), t17 (concurrencia) y t18 (navegador).
CALL tienda.sp_cambiar_password(1, '$2y$12$qmLHKY5xflsGqI2PL8H0o.QqmmJtxbch6rUPuoWfOC08nSf1g4Vqy');
CALL tienda.sp_guardar_configuracion_negocio(
    'Distribuidora Moto Piezas C.A.', 'J-07013380-5', 'Av. Principal, local 4, Barquisimeto',
    true, true, true, false, 'AUTOMATICO', 'ULTIMO', 30, 'CON_IVA',
    'Moto Piezas', '0251-1234567', 'ventas@motopiezas.com', 1);
CALL tienda.sp_confirmar_configuracion_inicial(1);
INSERT INTO tienda.productos (sku, nombre, unidad, costo_usd, margen_pct, exento_iva, exonerado_iva, stock_minimo, activo)
SELECT x.sku, x.nombre, 'UND', x.costo, 30, false, false, 2, true
  FROM (VALUES ('M2-ACEITE','Aceite motor 20W-50 1L',7.0),
               ('M2-BUJIA','Bujía NGK CR7HSA',2.5),
               ('M2-FILTRO','Filtro de aceite GX',3.2),
               ('M2-PASTILLA','Pastillas de freno delanteras',8.0),
               ('M2-LLANTA','Llanta 2.75-17',18.0),
               ('M2-CAMARA','Cámara 2.75-17',4.5),
               ('M2-CADENA','Cadena 428H 120 eslabones',9.0),
               ('M2-ESPEJO','Espejo retrovisor universal',3.8)) AS x(sku, nombre, costo)
  WHERE NOT EXISTS (SELECT 1 FROM tienda.productos p WHERE p.sku = x.sku);
UPDATE tienda.productos SET codigo_barras = '7700000000' || lpad(id::text, 4, '0') WHERE codigo_barras IS NULL;
UPDATE tienda.productos SET categoria_id = (SELECT min(id) FROM tienda.categorias) WHERE categoria_id IS NULL;
INSERT INTO tienda.proveedores (razon_social, rif, activo)
SELECT x.r, x.rif, true
  FROM (VALUES ('Repuestos del Este C.A.','J-33978211-2'),
               ('Accesorios Venezolanos C.A.','J-12449005-6')) AS x(r, rif)
  WHERE NOT EXISTS (SELECT 1 FROM tienda.proveedores p WHERE p.rif = x.rif);
-- La UT real (histórico verificado: Bs 9,00 desde 08/05/2023 y Bs 43,00 desde 02/06/2025) la siembra
-- 05_datos_iniciales.sql; no se duplica aquí. El seeder Laravel la garantiza también en instalaciones viejas.
DO $$
DECLARE pid bigint; rec record;
BEGIN
  FOR rec IN SELECT * FROM (VALUES ('M2-ACEITE',50),('M2-BUJIA',80),('M2-FILTRO',40),('M2-PASTILLA',30),
                                   ('M2-LLANTA',15),('M2-CAMARA',25),('M2-CADENA',20),('M2-ESPEJO',35))
                        AS x(sku, qty) LOOP
    SELECT min(id) INTO pid FROM tienda.productos WHERE sku = rec.sku;
    CALL tienda.sp_ajustar_stock(pid, rec.qty, 'INVENTARIO INICIAL', 1, true);
  END LOOP;
END $$;
INSERT INTO tienda.clientes (tipo_documento, documento, nombre, telefono, activo)
SELECT 'V', x.doc, x.nombre, '', true
  FROM (VALUES ('11111111','María Pérez'), ('22222222','Juan Rodríguez')) AS x(doc, nombre)
  WHERE NOT EXISTS (SELECT 1 FROM tienda.clientes c WHERE c.documento = x.doc);
INSERT INTO tienda.combos (sku, nombre, descripcion, precio_usd, activo)
SELECT 'KIT-DEMO-01', 'Kit afinación demo', 'Bujía + filtro de aceite', 7.00, true
  WHERE NOT EXISTS (SELECT 1 FROM tienda.combos WHERE sku = 'KIT-DEMO-01');
INSERT INTO tienda.combo_componentes (combo_id, producto_id, cantidad)
SELECT (SELECT id FROM tienda.combos WHERE sku = 'KIT-DEMO-01'),
       (SELECT id FROM tienda.productos WHERE sku = 'M2-BUJIA'), 1
  WHERE NOT EXISTS (SELECT 1 FROM tienda.combo_componentes cc JOIN tienda.combos c ON c.id = cc.combo_id
                     WHERE c.sku = 'KIT-DEMO-01' AND cc.producto_id = (SELECT id FROM tienda.productos WHERE sku = 'M2-BUJIA'));
INSERT INTO tienda.combo_componentes (combo_id, producto_id, cantidad)
SELECT (SELECT id FROM tienda.combos WHERE sku = 'KIT-DEMO-01'),
       (SELECT id FROM tienda.productos WHERE sku = 'M2-FILTRO'), 1
  WHERE NOT EXISTS (SELECT 1 FROM tienda.combo_componentes cc JOIN tienda.combos c ON c.id = cc.combo_id
                     WHERE c.sku = 'KIT-DEMO-01' AND cc.producto_id = (SELECT id FROM tienda.productos WHERE sku = 'M2-FILTRO'));
"""


def ejecutar(db: str, sql: str, etiqueta: str, conn_params: dict) -> None:
    # Los archivos pueden traer meta-comandos de psql (\echo …): psycopg2 no los entiende.
    sql = '\n'.join(l for l in sql.splitlines() if not l.lstrip().startswith('\\'))
    conn = psycopg2.connect(**conn_params, dbname=db)
    conn.autocommit = True
    cur = conn.cursor()
    try:
        cur.execute(sql)
    except Exception as e:
        msg = str(e).split('\n')[0][:300]
        print(f'  ✗ {etiqueta}: {msg}')
        raise
    finally:
        cur.close()
        conn.close()
    print(f'  ✓ {etiqueta}')


def admin_params() -> dict:
    return dict(host=HOST, port=PORT, user='postgres')


def main() -> None:
    if '--solo-verificar' in sys.argv:
        c = psycopg2.connect(**admin_params(), dbname='tienda')
        cur = c.cursor()
        cur.execute("SELECT count(*) FROM pg_tables WHERE schemaname = 'tienda'")
        print('tienda tablas:', cur.fetchone()[0])
        cur.close()
        c.close()
        return

    con = psycopg2.connect(**admin_params(), dbname='postgres')
    con.autocommit = True
    cur = con.cursor()
    for db in ('tienda', 'tienda_e2e', 'tienda_fiscal'):
        cur.execute('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()', (db,))
        cur.execute(f'DROP DATABASE IF EXISTS {db}')
    con.close()

    print('── tienda: esquema + funciones + semilla')
    con = psycopg2.connect(**admin_params(), dbname='postgres')
    con.autocommit = True
    cur = con.cursor()
    cur.execute('CREATE DATABASE tienda')
    # La fecha fiscal es la de America/Caracas (ANTES de sembrar: así CURRENT_DATE
    # de las tasas de la semilla coincide con fn_hoy() aunque el servidor esté en UTC).
    cur.execute("ALTER DATABASE tienda SET TIMEZONE TO 'America/Caracas'")
    cur.close()
    con.close()
    for f in TIENDA_FILES:
        ejecutar('tienda', open(f'{SQL}/{f}', encoding='utf-8').read(), f, admin_params())
    ejecutar('tienda', SEED_TIENDA, 'semilla tienda', admin_params())

    print('── verificación rápida')
    c = psycopg2.connect(**admin_params(), dbname='tienda')
    cur = c.cursor()
    cur.execute("SELECT count(*) FROM pg_tables WHERE schemaname = 'tienda'")
    print('tablas:', cur.fetchone()[0])
    cur.execute("SELECT table_name FROM information_schema.columns WHERE table_schema='tienda' AND column_name='negocio_id' ORDER BY 1")
    tablas = [r[0] for r in cur.fetchall()]
    print('columnas negocio_id (solo tfhka_* desde SQL 41):', tablas)
    extra = [t for t in tablas if not t.startswith('tfhka_')]
    print('  ✗ tablas del núcleo con negocio_id (debe ser 0):', extra if extra else 'ninguna')
    cur.execute('SELECT id, razon_social, nombre_comercial FROM tienda.negocio')
    print('negocio:', cur.fetchall())
    cur.close()
    c.close()
    print('LISTO: base «tienda» de tienda única instalada y sembrada.')
    # La semilla trae una tasa demo vigente HOY; si PHP está disponible, se
    # intenta una vez traer la tasa OFICIAL del BCV del día (API del sistema,
    # mismo comando que corre el programador cada mañana). Si no hay salida a
    # internet, queda la semilla y el botón «Cargar tasa de cambio BCV» la
    # actualiza cuando se quiera.
    # --sin-bcv: la cadena de pruebas (run_all.sh) exige las tasas FIJAS de la
    # semilla: las suites fiscales verifican cálculos sobre ellas y una tasa
    # del día real las rompería sin que haya bug alguno.
    import shutil
    import subprocess
    php = shutil.which('php') or '/tmp/phpstatic/php'
    if Path(php).exists() and '--sin-bcv' not in sys.argv:
        print('Sincronizando tasa del BCV (tasas:bcv)…')
        r = subprocess.run([php, 'artisan', 'tasas:bcv', '--forzar'],
                           cwd=Path(__file__).resolve().parents[2],
                           capture_output=True, text=True, timeout=120)
        for linea in (r.stdout + r.stderr).splitlines():
            if linea.strip():
                print('  ' + linea.strip())


if __name__ == '__main__':
    main()
