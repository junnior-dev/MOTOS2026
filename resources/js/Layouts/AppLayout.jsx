import { Link, router, usePage } from '@inertiajs/react';
import { Toast } from '../Components/kit';
import Bienvenida from '../Components/Bienvenida';
import { lanzarTour, NAV_TOUR } from '../lib/tour';
import { useState } from 'react';
import {
    BadgeCheck, Bell, BookOpen, Bike, CircleHelp, CreditCard, Puzzle, Tags, UserRound, Boxes, Coins, LayoutDashboard, Landmark, LockKeyhole, LockOpen, LogOut, Menu, Package, PackagePlus, PiggyBank, Plus, Radio, Receipt,
    ArrowLeftRight, BarChart3, HandCoins, ReceiptText, CalendarClock, KeyRound, WalletCards, Search, Settings, ShoppingCart, Truck, Users, Wallet, Wrench, X, Scale,
    ClipboardList, ClipboardCheck, ListChecks, PackageSearch, FileSpreadsheet,
} from 'lucide-react';
import { tasa as fTasa } from '../lib/format';

const MENU = [
    { grupo: 'Vender', items: [
        { t: 'Panel', i: LayoutDashboard, href: '/dashboard', admin: true },
        { t: 'Punto de venta', i: ShoppingCart, href: '/caja' },
        { t: 'Encargos', i: ClipboardList, href: '/encargos' },
        { t: 'Ventas', i: Receipt, href: '/ventas' },
        { t: 'Clientes', i: UserRound, href: '/clientes', admin: true },
        { t: 'Cuentas por cobrar', i: HandCoins, href: '/cuentas-por-cobrar' },
    ] },
    { grupo: 'Caja', items: [
        { t: 'Caja en vivo', i: Radio, href: '/caja-en-vivo', admin: true },
        { t: 'Cierres anteriores', i: Wallet, href: '/cierre-caja' },
        { t: 'Abrir caja', i: LockOpen, href: '/caja/abrir' },
        { t: 'Cerrar caja', i: LockKeyhole, href: '/caja/cerrar' },
        { t: 'Movimientos', i: WalletCards, href: '/movimientos-caja', admin: true },
        { t: 'Caja chica', i: PiggyBank, href: '/caja-chica', admin: true },
    ] },
    { grupo: 'Inventario', items: [
        { t: 'Productos', i: Package, href: '/productos', badge: 'stock', admin: true },
        { t: 'Existencias y kardex', i: Boxes, href: '/kardex', admin: true },
        { t: 'Órdenes de compra', i: ClipboardCheck, href: '/ordenes-compra', admin: true },
        { t: 'Compras', i: PackagePlus, href: '/compras', admin: true },
        { t: 'Proveedores', i: Truck, href: '/proveedores', admin: true },
        { t: 'Conteo físico', i: ListChecks, href: '/conteos', admin: true },
    ] },
    { grupo: 'Catálogo', items: [
        { t: 'Categorías', i: Tags, href: '/categorias', admin: true },
        { t: 'Combos y kits', i: PackageSearch, href: '/combos', admin: true },
        { t: 'Marcas', i: BadgeCheck, href: '/marcas', admin: true },
        { t: 'Modelos de moto', i: Bike, href: '/modelos', admin: true },
        { t: 'Compatibilidad', i: Puzzle, href: '/compatibilidad', admin: true },
    ] },
    { grupo: 'Finanzas', items: [
        { t: 'Tasas de cambio', i: Coins, href: '/tasas', admin: true },
        { t: 'Métodos de pago', i: CreditCard, href: '/metodos-pago', admin: true },
        { t: 'Libros fiscales', i: BookOpen, href: '/libros-fiscales', admin: true, fiscal: true },
        { t: 'Retenciones de ISLR', i: ReceiptText, href: '/retenciones-islr', admin: true, fiscal: true },
        { t: 'Declaración de ISLR', i: FileSpreadsheet, href: '/declaracion-islr', admin: true, fiscal: true },
        { t: 'Calendario fiscal', i: CalendarClock, href: '/calendario-fiscal', admin: true, fiscal: true },
        { t: 'Contabilidad', i: Scale, href: '/contabilidad', admin: true, fiscal: true },
        { t: 'Conciliación', i: ArrowLeftRight, href: '/conciliacion', admin: true },
        { t: 'Reportes', i: BarChart3, href: '/reportes', admin: true },
        { t: 'IGTF', i: Landmark, href: '/libros-fiscales?vista=igtf', admin: true, fiscal: true },
    ] },
    { grupo: 'Administración', items: [
        { t: 'Facturación digital', i: ReceiptText, href: '/facturacion-digital', admin: true },
        { t: 'Usuarios', i: Users, href: '/usuarios', admin: true },
        { t: 'Configuración', i: Settings, href: '/configuracion', admin: true },
    ] },
];

function Sidebar({ actual, usuario, negocio, stockBajo, verGuia, cerrar }) {
    // El cajero solo ve lo que le toca (App\Support\Permisos): vender, sus ventas y su caja.
    const admin = /admin/i.test(usuario.rol || '');
    // Tienda única: la parte fiscal/contable/ISLR está siempre disponible (la marca
    // `fiscal` de cada ítem es cosmética y ya no oculta nada).
    const grupos = MENU.map((g) => ({ ...g, items: g.items.filter((i) => admin || !i.admin) })).filter((g) => g.items.length);
    return (
        <div className="flex h-full flex-col border-r border-line bg-white text-text">
            <div className="flex h-16 shrink-0 items-center justify-between px-5">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-9 place-items-center rounded-lg bg-ink text-white"><Wrench className="size-[18px]" /></span>
                    <span className="text-[15px] font-semibold tracking-tight text-text">Repuestos de motos</span>
                </div>
                {cerrar && <button onClick={cerrar} aria-label="Cerrar menú" className="grid size-9 place-items-center rounded-md text-muted hover:bg-paper hover:text-text"><X className="size-5" /></button>}
            </div>

            <div className="mx-3 mb-2 rounded-lg border border-line bg-paper px-3 py-2.5">
                <p className="truncate text-sm font-medium text-text">{negocio}</p>
                <p className="mt-0.5 flex items-center gap-1.5 text-xs text-muted"><span className="size-1.5 rounded-full bg-ok" />Caja 1 abierta</p>
            </div>

            <nav className="flex-1 space-y-5 overflow-y-auto px-3 py-3" aria-label="Menú principal">
                {grupos.map((g) => (
                    <div key={g.grupo}>
                        <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted">{g.grupo}</p>
                        <ul className="space-y-0.5">
                            {g.items.map(({ t, i: Icon, href, badge }) => {
                                const on = actual === href;
                                const cls = `group relative flex items-center gap-3 rounded-lg px-3 py-2 text-[14px] font-semibold transition-colors ${on ? 'bg-brand-50 text-brand' : 'text-text/80 hover:bg-paper hover:text-brand'}`;
                                const inner = (<>
                                    {on && <span aria-hidden className="absolute -left-3 top-1.5 h-6 w-1 rounded-r bg-brand" />}
                                    <Icon className={`size-[18px] shrink-0 ${on ? 'text-brand' : 'text-muted group-hover:text-brand'}`} />
                                    <span className="flex-1 truncate">{t}</span>
                                    {badge === 'stock' && stockBajo > 0 && <span className="rounded-full bg-signal/15 px-2 py-0.5 text-xs font-semibold text-[#8a6100]" title="Productos con stock bajo">{stockBajo}</span>}
                                </>);
                                return <li key={t} data-tour={NAV_TOUR[href]}>{on ? <Link href={href} className={cls} aria-current="page">{inner}</Link> : <a href={href} className={cls}>{inner}</a>}</li>;
                            })}
                        </ul>
                    </div>
                ))}
            </nav>

            <div className="border-t border-line p-3">
                <div className="flex items-center gap-3 rounded-lg px-2 py-2">
                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-sm font-semibold text-white">{usuario.nombre.split(' ').map((p) => p[0]).slice(0, 2).join('')}</span>
                    <div className="min-w-0 flex-1"><p className="truncate text-sm font-medium text-text">{usuario.nombre}</p><p className="text-xs text-muted">{usuario.rol}</p></div>
                    {verGuia && <button onClick={verGuia} aria-label="Ver guía de nuevo" title="Ver guía de nuevo" className="grid size-9 place-items-center rounded-md text-muted hover:bg-paper hover:text-brand"><CircleHelp className="size-[18px]" /></button>}
                    <Link href="/cuenta/contrasena" aria-label="Cambiar mi contraseña" title="Cambiar mi contraseña" className="grid size-9 place-items-center rounded-md text-muted hover:bg-paper hover:text-brand"><KeyRound className="size-[18px]" /></Link>
                    <button onClick={() => router.post('/logout')} aria-label="Cerrar sesión" title="Cerrar sesión" className="grid size-9 place-items-center rounded-md text-muted hover:bg-paper hover:text-brand"><LogOut className="size-[18px]" /></button>
                </div>
            </div>
        </div>
    );
}

export default function AppLayout({ actual, titulo, subtitulo, usuario, negocio, tasaUsd, stockBajo = 0, alertas = 0, children }) {
    const [abierto, setAbierto] = useState(false);
    // Guía de bienvenida: si el usuario aún no la vio, la pantalla cubre TODO
    // (el panel queda oculto detrás, pero YA renderizado: el paso 1 del tour
    // lo resalta de verdad). Vale como «vista» si la termina O si la cierra.
    const primeraVez = usuario?.tour_completado === false;
    const [enTour, setEnTour] = useState(false);
    const lanzarGuia = () => lanzarTour(usuario?.rol, () => {});
    const props = usePage().props;
    const flash = props.flash?.ok;
    // El badge del encabezado siempre tiene tasa: la que pase la página, o la compartida
    // por el middleware (tasa_usd), o la que ya trajera la página en props.tasa / props.tasas.
    const tasaHeader = tasaUsd ?? props.tasa_usd ?? props.tasa ?? (Array.isArray(props.tasas) ? props.tasas.find((t) => t.par === 'USD')?.tasa : props.tasas?.USD);
    const stockHeader = stockBajo || props.stock_bajo || 0;
    return (
        <div className="min-h-screen bg-paper">
            <Toast texto={flash} />
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-[268px] lg:block"><Sidebar {...{ actual, usuario, negocio, stockBajo: stockHeader, verGuia: lanzarGuia }} /></aside>
            {abierto && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/50" onClick={() => setAbierto(false)} />
                    <div className="absolute inset-y-0 left-0 w-[280px] max-w-[85%]"><Sidebar {...{ actual, usuario, negocio, stockBajo: stockHeader, verGuia: lanzarGuia }} cerrar={() => setAbierto(false)} /></div>
                </div>
            )}

            <div className="min-w-0 lg:pl-[268px]">
                <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-white/85 px-4 backdrop-blur sm:px-6 lg:px-8">
                    <button onClick={() => setAbierto(true)} aria-label="Abrir menú" className="grid size-10 place-items-center rounded-lg border border-line lg:hidden"><Menu className="size-5" /></button>
                    <div className="min-w-0">
                        <h1 className="truncate text-[17px] font-semibold tracking-[-0.01em]">{titulo}</h1>
                        {subtitulo && <p className="hidden truncate text-[13px] text-muted sm:block">{subtitulo}</p>}
                    </div>
                    <div className="ml-auto flex items-center gap-2 sm:gap-3">
                        {!ancho && <label className="relative hidden md:block">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                            <input type="search" placeholder="Buscar repuesto, SKU o factura" className="h-10 w-64 rounded-lg border border-line bg-paper pl-9 pr-3 text-sm placeholder:text-[#8d97a2] focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand xl:w-80" />
                        </label>}
                        <span className="hidden items-center gap-2 rounded-lg border border-line bg-white px-3 py-2 text-[13px] sm:flex" title="Tasa del dólar en bolívares">
                            <Coins className="size-4 text-muted" /><span className="text-muted">1 US$ =</span><span className="num font-semibold">{tasaHeader > 0 ? `Bs ${fTasa(tasaHeader)}` : 'sin tasa'}</span>
                        </span>
                        <button aria-label="Avisos" className="relative grid size-10 place-items-center rounded-lg border border-line bg-white hover:bg-paper">
                            <Bell className="size-[18px]" />{alertas > 0 && <span className="absolute right-2 top-2 size-2 rounded-full bg-danger ring-2 ring-white" />}
                        </button>
                        <a href="/caja" className="inline-flex h-10 items-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-600"><Plus className="size-4" /><span className="hidden sm:inline">Nueva venta</span></a>
                    </div>
                </header>
                <main className="mx-auto min-w-0 max-w-[1680px] px-4 py-4 sm:px-6 lg:px-8">{children}</main>
            </div>
            {primeraVez && !enTour && (
                <Bienvenida nombre={usuario.nombre} rol={usuario.rol}
                    onIniciar={() => { setEnTour(true); lanzarTour(usuario.rol, () => router.post('/tour/completar')); }}
                    onSaltar={() => router.post('/tour/completar')} />
            )}
        </div>
    );
}
