# README PRUEBAS 2026 · Repuestos de Motos (POS de tienda única)

> **Documento de referencia de TODAS las pruebas del sistema**, con el estado de cada
> suite, cómo ejecutarlas y los resultados de la última ronda completa verificada.
> **Este archivo es la ÚNICA fuente de verdad de los totales**: los otros documentos
> (CAMBIOS_TIENDA_UNICA.md, README_VERIFICACION_SENIAT.md) lo citan, no duplican cifras.

- Última ronda completa: **19 de noviembre de 2026 · 4.ª ronda** (integración del XML de retenciones de ISLR del Portal Fiscal + cadena completa re-verificada con tasas semilla fijas)
- **Suites funcionales Python/E2E: 785 pruebas · 0 fallos** (t01–t15: 239 · f01–f17: 249 · t21 multimoneda: 20 · t23 XML ISLR: 40 · t16: 65 · t17: 26 · t18: 20 · t19: 48 · t20: 37 · t21 facturación: 31 · t22 tour: 18 · continuidad c1–c5: 23)
- **Suite Pest/PHPUnit (Laravel): 156 pruebas · 851 aserciones · 0 fallos** (incluye la matriz de autorización, Money/arqueo multimoneda, la cola fiscal con worker real y el XML del Portal Fiscal)
- **Test de idempotencia SQL: re-aplicación de los 32 scripts + invariantes semánticos · 0 fallos**
- **Concurrencia PostgreSQL: 600 operaciones · 0 errores** (100 conexiones concurrentes, 200 ventas de carrera, stock sin negativos)
- **Trazabilidad: 36 requisitos cubiertos · 0 huérfanos · 0 sin respaldo**
- **Auditorías: composer audit 0 advisories · npm audit 0 vulnerabilidades**
- **TOTAL: 972 verificaciones · 0 fallos**

## Historial de verificaciones

| Fecha | Alcance | Total | Notas |
|---|---|---:|---|
| 17/11/2026 · 1.ª | Conversión a tienda única: t01–t15 + f01–f17 (439) + t16–t18 (111) + t19/t20 (84) + Pest (81) | 715 · 0 fallos | Hallazgos #1–#6 corregidos (redondeo de compra, sp_ del endurecimiento borrado, listas canónicas del CHECK, validador de crédito, «Fletes» ISLR, restauro de plantilla) |
| 17/11/2026 · 2.ª | Auditoría estructural: barrido de huérfanos (objetos borrados del SaaS vs referencias reales), test de idempotencia nuevo (corrigió el 01 y el 21), UT real sembrada con histórico verificado, matriz Pest de páginas admin por rol, f09 y t20 endurecidos | **748 · 0 fallos** | Hallazgos #7–#10 corregidos (ver README_VERIFICACION_SENIAT.md §3b) |
| 19/11/2026 · 3.ª | Cadena completa reconstruida en entorno limpio (PostgreSQL 17, PHP 8.4.23, cliente psql/pg_dump, Playwright/Chromium, assets Vite): t01–t15 + t21 multimoneda + f01–f17 (508) + t16–t20 (196) + t21 facturación (31) + t22 tour (18) + Pest (146) + continuidad c1–c5 (23) + concurrencia (600 ops) + trazabilidad (35/35) + audits | **922 · 0 fallos** | Fixes: **regresión del arqueo multimoneda** (fn_resumen_apertura del SQL 24 había perdido el fondo EUR/COP que restauraba el 16 — caja con fondo en 4 divisas no mostraba €/COP), anticipo de encargos dependiente de la tasa del día (t09), V8e de t20 comparaba historial completo sin snapshot de compras (contaminación entre suites), t18 sin manejo del tour de bienvenida (SQL 44 bloqueaba los clics), t21 facturación sin caja abierta (dependencia de orden), trazabilidad (bug de glob + referencia fantasma m11) |
| 19/11/2026 · 4.ª | **Integración del XML de retenciones de ISLR (Portal Fiscal, TRI.GR.03.0013)**: SQL 46 (códigos del Anexo 6.1 + fn_retenciones_islr_xml), botón «XML SENIAT» en Retenciones de ISLR, comando `fiscal:islr-xml`, suite t23 (40) + Pest RetencionIslrXmlTest (10), requisito nuevo en trazabilidad (36/36); además flag `--sin-bcv` en setup_db para que la cadena use las tasas SEMILLA fijas (la sincronización BCV en vivo rompía f03/f14 sin bug real); smoke real del comando con verificación campo por campo | **972 · 0 fallos** | Detalle del mapeo y del procedimiento mensual: README_XML_RETENCIONES_ISLR.md |

---

## 1 · Suites funcionales Python/E2E — 785 pruebas, 0 fallos

Reinician su clon desde TEMPLATE `tienda` antes de cada suite: la plantilla nunca se contamina.

| Suite | Alcance | Pruebas | Estado |
|---|---|---:|---|
| t01–t15 | Operativa del negocio: instalación, catálogo, compras, POS, crédito, notas, encargos, conteos, cierre, usuarios, integridad | 239 | ✅ OK |
| t21 multimoneda | Caja multimoneda: 4 divisas, vueltos cruzados, efectivo COP sin céntimos, snapshot inmutable, doble cierre | 20 | ✅ OK |
| t23 XML ISLR | Relación informativa XML del Portal Fiscal (TRI.GR.03.0013): atributos de la raíz, xs:sequence, factura últimos 10 o «0», control secuencial ≤ 8 o «NA», códigos del Anexo 6.1 por tipo de beneficiario, sustraendo, anuladas fuera, períodos vacíos, RIF inválido | 40 | ✅ OK |
| f01–f17 | Fiscal venezolano: IVA 16 %, IGTF 3 %, retenciones, ISLR anual, ajuste cambiario, partida doble, invariantes, estados imposibles | 249 | ✅ OK |
| t16 | Páginas Inertia por HTTP real (componentes, props, partials, redirects por rol) | 65 | ✅ OK |
| t17 | Concurrencia: dos cajeros a la vez, correlativo sin huecos, stock sin negativos, rehash de contraseñas | 26 | ✅ OK |
| t18 | Navegador real (Playwright + Chromium): flujos de UI sin un solo error de consola | 20 | ✅ OK |
| t19 | Acceso y configuración por HTTP: login/logout/throttle, asistente de registro en base virgen, las 5 secciones de Configuración, alícuota programada y cancelada; restaura la plantilla SIEMPRE (atexit) | 48 | ✅ OK |
| t20 | Validación fiscal con datos simulados (faker): 160 ventas, 45 compras, retenciones; IVA por línea, IGTF, Decreto 1808, partida doble global y por mayor (débito/crédito), kardex, correlativo | 37 | ✅ OK |
| t21 facturación | Facturación fiscal por HTTP real: los DOS modos del SENIAT (imprenta digital TFHKA y máquina fiscal SIMULADOR), correlativo, notas de crédito, anulación legal, autorización admin/cajero | 31 | ✅ OK |
| t22 tour | Tour de bienvenida «Conoce SmartMotos» en navegador real: 6 pasos de driver.js, persistencia de tour_completado | 18 | ✅ OK |
| c1–c5 continuidad | Backup pg_dump -Fc real, restore con conteos e invariantes, migración incremental idempotente sobre datos vivos, rollback con huella md5, search_path sobrevive al restore | 23 | ✅ OK |
| **Total** | | **785** | **✅ 0 fallos** |

Ejecutar (todo en orden, con PostgreSQL en 5433/socket /tmp):

```bash
bash scripts/run_all.sh            # la cadena completa (incluye el test de idempotencia)
python3 scripts/infra/run_suites.py /tmp/pruebas.log   # t01–t15 + f01–f17
python3 tests/e2e/t19_acceso_configuracion.py          # necesita el servidor en :8000
python3 tests/e2e/t20_validacion_fiscal_simulada.py    # clon propio; se borra al terminar
```

---

## 2 · Suite Pest/PHPUnit (Laravel) — 156 pruebas · 851 aserciones · 0 fallos

Corre contra la base **dedicada** `tienda_pest` (clon de la plantilla; ver phpunit.xml),
nunca contra la base del servidor.

| Archivo (tests/) | Qué prueba |
|---|---|
| `Feature/PaginasInertiaTest.php` | `$this->get()` + `assertInertia` oficial: componente correcto, conteo de props certificado, versión de assets, archivo .jsx existente; invitado/admin/cajero; JSON parcial X-Inertia |
| `Feature/AutorizacionEndpointTest.php` | **Autorización POR ENDPOINT y POR PÁGINA**: 8 endpoints admin × (invitado → redirect · cajero → 302/403 y NINGÚN dato cambia · admin → pasa) **+ 15 páginas de administración GET × (cajero → 302 a /caja o 403 · admin → pasa)** |
| `Feature/FlujoVentaTest.php` | Flujo completo POR LA PILA LARAVEL: apertura → cotización → venta contado → stock/kardex/pagos → anulación con retorno de stock |
| `Feature/ValidacionesFormularioTest.php` | Validaciones de formulario + buscador del POS |
| `Feature/CombosComponentesTest.php` | Combos: precio del kit, stock por componente, pantalla |
| `Feature/CambioPasswordObligatorioTest.php` | El usuario recién creado debe cambiar su contraseña antes de operar |
| `Feature/CierreCajaHttpTest.php` | El cierre de caja por HTTP (autorización y datos del turno) |
| `Feature/TourBienvenidaTest.php` | Estado del tour de bienvenida en el contexto de página |
| `Feature/FiscalColaTest.php` | **Pipeline REAL de la cola fiscal** (SQL 45 + driver «database» auténtico): encolado en public.jobs, afterCommit, lock único por venta, worker real `queue:work --once` (éxito, reintento curado, agotamiento → failed_jobs), fiscal:reintentar sin fiscal activo |
| `Feature/ArqueoMultimonedaTest.php` | Reglas del arqueo multimoneda: conteo exigido por moneda activa, precisión COP 0, nota obligatoria en descuadre, tolerancia del medio centavo |
| `Feature/RetencionIslrXmlTest.php` | **El XML del Portal Fiscal por HTTP y por función**: atributos RifAgente/Periodo, orden del xs:sequence, códigos 002/004 por tipo, factura últimos 10 o «0», control secuencial o «NA», sustraendo 002, anuladas fuera, descarga con nombre oficial, períodos mal formados y RIF inválido |
| `Unit/MoneyTest.php` | Contrato de aritmética exacta de dinero (bcadd/bccomp): precisión por moneda, resta con signo, tolerancia |
| `Unit/HelpersTest.php` | Matriz de `Permisos` (única fuente de roles) |

Ejecutar: `./vendor/bin/pest`

---

## 3 · Base de datos de pruebas

| Base | Uso |
|---|---|
| `tienda` | Plantilla limpia (esquema 01–39 + semilla de la suite) |
| `tienda_e2e` · `tienda_fiscal` | Clones para las suites t/f |
| `tienda_conc` | Clon para t17 |
| `tienda_pest` | Clon para Pest |
| `tienda_faker` | Clon efímero de t20 (se borra al terminar) |
| `tienda_registro` | Base virgen efímera de t19 (asistente de primer inicio; se borra al terminar) |
| `tienda_idem` | Base efímera del test de idempotencia (se borra al terminar) |

Instalación de la plantilla: `python3 scripts/infra/setup_db.py` (necesita `psycopg2`).

---

## 4 · Seguridad complementaria

| Verificación | Herramienta |
|---|---|
| Dependencias PHP (CVEs) | `composer audit` |
| Dependencias JS (CVEs) | `npm audit` |
| Throttle del login (5/min por IP) | demostrado por HTTP real en las suites |
| CSRF/XSS y cookies endurecidas | `SeguridadCabeceras` + tests Pest |
| Autorización por rol a nivel de página Y de endpoint | t14 + t16 + Pest (endpoints y páginas GET) |
| Trazabilidad bidirecional requisitos ↔ suites | `python3 tests/trazabilidad.py` |

---

## 5 · Idempotencia de los SQL (regresión estructural)

`bash scripts/infra/test_idempotencia.sh` (integrado a `run_all.sh` y al job `idempotencia` de CI):

1. Instala los 30 archivos de `database/sql/` en una base limpia (`tienda_idem`).
2. **Re-aplica cada archivo una segunda vez**: si uno falla, rompe un CHECK o duplica catálogos, el test falla.
3. Verifica invariantes: los 5 procedimientos del endurecimiento borrado existen (`sp_cancelar_alicuota`, `sp_alta_moneda`, `sp_moneda_activa`, `sp_cambiar_alicuota`, `sp_registrar_tasa`), el CHECK `asientos_origen_tipo_check` conserva `RETENCION_ISLR` una sola vez, `v_libro_ventas` existe, catálogos sin duplicados y el histórico real de UT vigente (Bs 43,00).

Corrigió en su primera corrida: el archivo 01 no era re-aplicable (30 tablas, 34 índices y constraints sin guardas) y el 21 chocaba con la migración del 22 (`ON CONFLICT` sin target y función duplicada).
