# Relación informativa XML de retenciones de ISLR (Portal Fiscal del SENIAT)

> Qué genera el sistema, con qué reglas del manual y cómo se presenta cada mes.
> La fuente técnica: manual SENIAT **TRI.GR.03.0013 «Retenciones de ISLR. Relación
> informativa»** (Providencia Administrativa N° 0299 del 12/12/2008, G.O. 39.079;
> porcentajes del **Decreto 1.808** del 23/04/1997, G.O. 36.203). La presentación es
> exclusivamente por el Portal Fiscal (seniat.gob.ve).

## 1 · Qué faltaba y qué se integró

El sistema ya retenía ISLR a proveedores de servicios (SQL 20: `retenciones_islr`,
sustraendo del Parágrafo Segundo art. 9, entero mensual, asientos) pero **no generaba
el archivo XML** que el Portal Fiscal exige por cada ejercicio. Se integró:

| Pieza | Archivo | Qué hace |
|---|---|---|
| Códigos del Anexo 6.1 por concepto y tipo de beneficiario | `database/sql/46_islr_xml_relacion.sql` | Columnas `codigo_seniat_pn` / `codigo_seniat_pj` en `islr_conceptos` con el mapeo del Decreto 1.808 |
| Generador del XML | idem | `fn_retenciones_islr_xml(año, mes)` → el documento completo con las reglas del manual |
| Descarga por HTTP | `app/Http/Controllers/RetencionesIslrController.php` + ruta `/retenciones-islr/xml/{mes}` | Botón **«XML SENIAT»** en la página de Retenciones de ISLR (solo administrador) |
| Carga mensual por consola | `php artisan fiscal:islr-xml [AAAA-MM]` | Escribe el archivo en `storage/app/islr/` y resume el período |
| Pruebas | `tests/e2e/t23_islr_xml.py` (40 verificaciones) + `tests/Feature/RetencionIslrXmlTest.php` (10) | Cada regla del manual validada contra datos reales de la base |

## 2 · Mapeo de conceptos del sistema → códigos del Anexo 6.1

| Concepto (islr_conceptos) | Persona NATURAL | Persona JURÍDICA | % PN | % PJ | Sustraendo |
|---|---|---|---:|---:|---|
| HONORARIOS | **002** | **004** | 3 % | 5 % | sí (002) |
| COMISIONES | **018** | **020** | 3 % | 5 % | sí (018) |
| CONTRATISTAS | **053** | **055** | 1 % | 2 % | sí (053) |
| ALQUILER_INMUEBLES | **057** | **059** | 3 % | 5 % | sí (057) |
| ALQUILER_MUEBLES | **061** | **063** | 3 % | 5 % | sí (061) |
| FLETES | **071** | **072** | 1 % | 3 % | sí (071) |

Los porcentajes y el sustraendo ya los aplicaba `fn_islr_calcular` desde el SQL 20;
el XML reporta la retención **resultante** (el sustraendo no se declara, el monto
retenido ya lo incluye). Los códigos PN de la tabla son exactamente los que el
manual declara con sustraendo automático (002, 018, 053, 057, 061, 071).

**Pagos a sujetos pasivos especiales (34 %)**: el Parágrafo Cuarto del art. 9 del
Decreto 1.808 retiene el 34 % sobre la totalidad del pago. El archivo acepta
cualquier porcentaje entre 0 y 100: si tu contador registra la retención con
`porcentaje = 34` (concepto del anexo que corresponda), el XML la reporta como
34.00 sin cambios de código.

## 3 · Reglas del manual que implementa el generador

1. **Raíz**: `<RelacionRetencionesISLR RifAgente="..." Periodo="AAAAMM">` — el RIF
   de la tienda como agente (letra V/E/J/P/G + 9 dígitos, sin guiones) y el período
   de la declaración. Los datos del detalle van en el orden estricto del XSD.
2. **Un `<DetalleRetencion>` por retención ACTIVA del período**; las ANULADAS no se
   reportan. Período sin registros → no hay archivo (el Portal exige al menos un
   registro).
3. **NumeroFactura**: máximo 10 alfanuméricos; con más de 10 van los ÚLTIMOS diez;
   sin factura → `0`. El número se toma de `retenciones_islr.documento` (la factura
   del proveedor pagado).
4. **NumeroControl**: SOLO el secuencial numérico, máximo 8 dígitos — del formato
   clásico `0001-00001234` sale `00001234` (se toma de la compra registrada con esa
   factura). Sin control → `NA`.
5. **CodigoConcepto**: 3 dígitos del Anexo 6.1 según el tipo de beneficiario
   (persona natural → código PN; persona jurídica → código PJ).
6. **MontoOperacion**: total de la operación en Bs (la base imponible de la
   retención) con punto decimal y 2 decimales.
7. **PorcentajeRetencion**: el % aplicado, entre 0 y 100 con 2 decimales.
8. **RIFs inválidos**: si un beneficiario tiene un RIF que no cumple el patrón, el
   archivo NO se genera y el error señala el número de la retención a corregir.
9. **Exclusión del manual**: los intereses pagados por bancos e instituciones
   financieras a personas naturales residentes NO se declaran (la tienda no paga
   ese tipo de remuneraciones; si llegara a pagarlos, no deben ir al archivo).

## 4 · Procedimiento mensual

**Por la pantalla (recomendado)**: Retenciones de ISLR → ubica el mes con las flechas
← → → botón **«XML SENIAT»** → el navegador descarga
`retenciones_islr_AAAAMM_<RIF>.xml`. Presenta ese archivo en el Portal Fiscal
(ISLR → Declaración de Salarios y Otras Retenciones) y guarda el acuse de recibo.

**Por consola (cierre sin sesión abierta)**:

```bash
php artisan fiscal:islr-xml              # el mes en curso
php artisan fiscal:islr-xml 2026-11      # un período específico
```

El comando escribe el archivo en `storage/app/islr/`, imprime cuántas retenciones
incluye y el total retenido del período, y rechaza períodos vacíos con un aviso
claro (el Portal Fiscal rechaza un archivo sin registros).

**Después de presentar**: marca el pago con **«Registrar pago al SENIAT»** (el
entero) como cualquier mes — el XML no cambia el flujo del entero.

## 5 · Nota de verificación de la fuente

La versión del manual que circula públicamente y que usan los programas de los
agentes de retención es la **v2.3 (02/2010)** del mismo código TRI.GR.03.0013; el
resumen de una «v2.4 · Mayo 2026» aportado por el usuario no está disponible en
fuentes públicas verificables (solo v2.3, y la tabla de porcentajes vigente que
publican Grant Thornton y Moore Venezuela con la UT 43). El generador implementa
las reglas que son idénticas en ambas: jerarquía del XSD, atributos de la raíz,
reglas de factura/control/código/decimales, lista de sustraendos y exclusión de
intereses. Si el SENIAT publicara cambios de estructura en una v2.4 real, el punto
de ajuste es único: `fn_retenciones_islr_xml()` en el SQL 46 (y sus pruebas t23 /
Pest lo detectarían).

## 6 · Ejemplo generado (anónimo)

```xml
<?xml version="1.0" encoding="utf-8"?>
<RelacionRetencionesISLR RifAgente="J070133805" Periodo="202611">
 <DetalleRetencion><RifRetenido>V123456781</RifRetenido><NumeroFactura>0</NumeroFactura><NumeroControl>NA</NumeroControl><CodigoConcepto>002</CodigoConcepto><MontoOperacion>10000.00</MontoOperacion><PorcentajeRetencion>3.00</PorcentajeRetencion></DetalleRetencion>
 <DetalleRetencion><RifRetenido>J308554839</RifRetenido><NumeroFactura>FAC123456</NumeroFactura><NumeroControl>00001234</NumeroControl><CodigoConcepto>004</CodigoConcepto><MontoOperacion>10000.00</MontoOperacion><PorcentajeRetencion>5.00</PorcentajeRetencion></DetalleRetencion>
</RelacionRetencionesISLR>
```
