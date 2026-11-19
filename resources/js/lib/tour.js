// Tour de bienvenida «Conoce SmartMotos» con driver.js (MIT, ~5 kB, sin dependencias).
// Cada rol tiene SU guía: el administrador recorre el negocio completo (6 pasos,
// desde el Panel hasta Usuarios) y el cajero solo el punto de venta (4 pasos).
// Los pasos apuntan a atributos data-tour estables (no a clases de Tailwind:
// esas cambian con cualquier ajuste de estilos).
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';

/** data-tour de cada ítem del menú lateral, por su ruta. */
export const NAV_TOUR = {
    '/dashboard': 'nav-panel',
    '/productos': 'nav-productos',
    '/caja': 'nav-caja',
    '/facturacion-digital': 'nav-facturacion',
    '/reportes': 'nav-reportes',
    '/usuarios': 'nav-usuarios',
};

const ADMIN = 6;
const CAJERO = 4;

const PASOS_ADMIN = [
    { element: '[data-tour="panel-resumen"]', popover: { title: 'Panel', description: 'Tu resumen de cada día: la tasa del BCV, lo vendido, la utilidad y qué necesita tu atención (stock bajo y precios por confirmar).', side: 'bottom', align: 'start' } },
    { element: '[data-tour="nav-productos"]', popover: { title: 'Productos e inventario', description: 'Aquí administras el catálogo: precios de compra y venta, stock mínimo con alertas, presentaciones y compatibilidad con cada modelo de moto.', side: 'right' } },
    { element: '[data-tour="nav-caja"]', popover: { title: 'Punto de venta', description: 'La pantalla donde el cajero cobra: escanea o busca el repuesto, elige el cliente y cobra en bolívares, dólares o con crédito.', side: 'right' } },
    { element: '[data-tour="nav-facturacion"]', popover: { title: 'Facturación digital (TFHKA)', description: 'Conecta tu imprenta digital autorizada (The Factory HKA): facturas y notas de crédito emitidas conforme a la Providencia SNAT/2024/000102, o usa la numeración manual.', side: 'right' } },
    { element: '[data-tour="nav-reportes"]', popover: { title: 'Reportes y declaraciones', description: 'Rentabilidad, compras, libros de IVA, retenciones y la declaración de ISLR anual con las unidades tributarias del año. Todo sale de tus ventas reales.', side: 'right' } },
    { element: '[data-tour="nav-usuarios"]', popover: { title: 'Usuarios y configuración', description: 'Crea los cajeros con su contraseña y revisa la configuración del negocio (datos fiscales, régimen, IGTF). Cada usuario ve solo lo suyo.', side: 'right' } },
];

const PASOS_CAJERO = [
    { element: '[data-tour="pos-pantalla"]', popover: { title: 'Tu pantalla de trabajo', description: 'Este es el punto de venta. A la izquierda arma la venta; a la derecha el cliente y el total. Todo se calcula solo.', side: 'top' } },
    { element: '[data-tour="pos-buscador"]', popover: { title: 'Buscar y agregar repuestos', description: 'Escanea el código de barras o escribe: bujía, aceite 20W50… También funciona por marca, categoría o la moto compatible. Enter agrega el producto a la venta.', side: 'bottom' } },
    { element: '[data-tour="pos-pagos"]', popover: { title: 'Cliente y pago', description: 'El cliente es opcional (Consumidor final). Paga en bolívares a la tasa del día o en divisas: el IGTF del 3 % se suma solo al cobrar en divisas.', side: 'left' } },
    { element: '[data-tour="pos-emitir"]', popover: { title: 'Emitir la venta', description: 'Con F4 o este botón cobras: se registra la venta, sale la factura o nota de venta y puedes reimprimirla. Para anular, pide ayuda al administrador.', side: 'left' } },
];

/** Cantidad de pasos que verá cada rol (para el texto de la bienvenida). */
export const pasosPorRol = (rol) => (/admin/i.test(rol || '') ? ADMIN : CAJERO);

/**
 * Lanza el tour del rol. `alTerminar` corre SIEMPRE: al completar Y al cerrar
 * con la X (que también cuenta como visto para no repetirle la guía a cada rato).
 */
export function lanzarTour(rol, alTerminar) {
    const pasos = /admin/i.test(rol || '') ? PASOS_ADMIN : PASOS_CAJERO;
    const drv = driver({
        showProgress: true,
        progressText: 'Paso {{current}} de {{total}}',
        nextBtnText: 'Siguiente',
        prevBtnText: 'Atrás',
        doneBtnText: '¡Listo!',
        allowClose: true,
        overlayOpacity: 0.72,
        popoverClass: 'tour-popover',
        onDestroyed: () => alTerminar?.(),
        steps: pasos,
    });
    drv.drive();
    return drv;
}
