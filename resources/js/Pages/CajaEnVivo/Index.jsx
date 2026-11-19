import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Banknote, Landmark, LockKeyhole, Receipt, RefreshCw, ShoppingCart, Store, Wallet, WalletCards } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { Card, Vacio } from '../../Components/kit';
import { Button } from '../../Components/ui';
import { SIMBOLO, n0, n2, usd, bs } from '../../lib/format';

/* Caja en vivo: lo que hay ahora mismo en cada caja abierta de la tienda.
   Regla multimoneda: cada divisa se muestra en SU moneda nativa, nunca mezcladas;
   USD y VES son la base visible y COP/EUR aparecen solos cuando tienen saldo.
   Igual que el POS, se pone al día sola (recarga cada 25 s si la pestaña está visible). */

const haceMin = (iso) => Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
const haceTxt = (iso) => { const m = haceMin(iso); return m < 1 ? 'un momento' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`; };
const monto = (m, v) => (m === 'COP' ? `COP ${n0(v)}` : m === 'VES' ? bs(v) : `${SIMBOLO[m] || m} ${n2(v)}`);

function LineasMonedas({ lista, primera = 'text-[20px] font-bold', resto = 'text-[13px] font-semibold' }) {
    if (!lista || lista.length === 0) return <span className="text-[13px] text-muted">—</span>;
    return (
        <div className="mt-1 grid gap-0.5">
            {lista.map((x, i) => (
                <p key={x.moneda} className={`num leading-tight ${i === 0 ? primera : `${resto} text-muted`}`}>{monto(x.moneda, x.monto)}</p>
            ))}
        </div>
    );
}

function Celda({ icon: Icon, titulo, children }) {
    return (
        <div className="bg-white p-4 sm:p-5">
            <p className="flex items-center gap-2 text-[13px] font-medium text-muted"><Icon className="size-4 shrink-0" aria-hidden />{titulo}</p>
            {children}
        </div>
    );
}

export default function CajaEnVivo(p) {
    const { cajas, tiene_caja_abierta, usuario, negocio, fecha_larga, tasa_usd, stock_bajo } = p;
    const [, setTick] = useState(0);
    useEffect(() => {
        let t = null;
        const arranca = () => { t = setInterval(() => { setTick((v) => v + 1); router.reload({ only: ['cajas'], preserveScroll: true, preserveState: true }); }, 25000); };
        const parar = () => t && clearInterval(t);
        const visible = () => { setTick((x) => x + 1); document.hidden ? parar() : arranca(); };
        arranca();
        document.addEventListener('visibilitychange', visible);
        return () => { parar(); document.removeEventListener('visibilitychange', visible); };
    }, []);
    const recargar = () => router.reload({ only: ['cajas'], preserveScroll: true });
    const suma = (k) => cajas.reduce((s, c) => s + c[k], 0);
    const junta = (k) => {
        const por = {};
        cajas.forEach((c) => c[k].forEach((x) => { por[x.moneda] ??= 0; por[x.moneda] += x.monto; }));
        const orden = { USD: 0, VES: 1, EUR: 2, COP: 3 };
        return Object.entries(por).filter(([, v]) => v >= 0.005).map(([moneda, v]) => ({ moneda, monto: v }))
            .sort((x, y) => (orden[x.moneda] ?? 9) - (orden[y.moneda] ?? 9));
    };
    const gavetaKpi = junta('gaveta_monedas');
    const bancoKpi = junta('banco_monedas');
    // Referencial consolidado (solo como dato secundario, nunca sustituye el desglose por divisa):
    // USD queda tal cual; VES se convierte con la tasa visible del encabezado.
    const tasaVes = tasa_usd || 1;
    const refGaveta = gavetaKpi.reduce((s, x) => s + (x.moneda === 'USD' ? x.monto : x.moneda === 'VES' ? x.monto / tasaVes : 0), 0);
    const refBanco = bancoKpi.reduce((s, x) => s + (x.moneda === 'USD' ? x.monto : x.moneda === 'VES' ? x.monto / tasaVes : 0), 0);
    const esAdmin = usuario?.rol === 'Admin';

    return (
        <AppLayout actual="/caja-en-vivo" titulo="Caja en vivo" subtitulo="Lo que hay ahora en cada caja abierta. Tu sucursal primero; se pone al día sola." usuario={usuario} negocio={negocio} tasaUsd={tasa_usd} stockBajo={stock_bajo}>
            <Head title="Caja en vivo" />
            <p className="mb-1 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.14em] text-muted"><span className="inline-block h-px w-6 bg-line" aria-hidden />Dinero · Caja</p>
            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <h1 className="text-[26px] font-bold tracking-[-0.02em]">Caja en vivo</h1>
                <div className="flex items-center gap-2">
                    <a href="/cierre-caja" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-white px-3.5 text-[14px] font-semibold hover:bg-paper"><WalletCards className="size-4" />Cierres anteriores</a>
                    {!tiene_caja_abierta
                        ? <Button onClick={() => router.visit('/caja/abrir')} className="h-10 px-4"><LockKeyhole className="size-4" />Abrir caja</Button>
                        : <Button onClick={() => router.visit('/caja/cerrar')} className="h-10 px-4"><LockKeyhole className="size-4" />Cerrar mi caja</Button>}
                </div>
            </div>

            {cajas.length === 0 ? (
                <Card><Vacio icon={Wallet} titulo="No hay cajas abiertas" texto="Cuando alguien abra su caja, aparecerá aquí en vivo con su fondo, sus ventas y lo que lleva en la gaveta.">
                    {!tiene_caja_abierta && <Button onClick={() => router.visit('/caja/abrir')} className="h-11 px-5"><LockKeyhole className="size-4" />Abrir caja</Button>}
                </Vacio></Card>
            ) : (
                <>
                    <Card className="mb-5 overflow-hidden">
                        <div className="grid grid-cols-2 gap-px bg-line xl:grid-cols-4">
                            <Celda icon={Store} titulo="Cajas abiertas">
                                <p className="num mt-2 text-[26px] font-semibold leading-none tracking-[-0.02em]">{n0(cajas.length)}</p>
                                <p className="mt-2 text-[12.5px] leading-snug text-muted"><a href="/cierre-caja" className="font-semibold text-brand hover:underline">Ver las de hoy</a></p>
                            </Celda>
                            <Celda icon={Receipt} titulo="Ventas en estos turnos">
                                <p className="num mt-2 text-[26px] font-semibold leading-none tracking-[-0.02em]">{n0(suma('ventas'))}</p>
                                <p className="mt-2 text-[12.5px] leading-snug text-muted">Sin contar las anuladas</p>
                            </Celda>
                            <Celda icon={Banknote} titulo="En las gavetas">
                                <LineasMonedas lista={gavetaKpi} primera="text-[22px] font-semibold" />
                                <p className="mt-2 flex flex-wrap items-baseline gap-x-2 text-[12.5px] leading-snug text-muted">en efectivo{refGaveta > 0.004 && <span className="num text-[11.5px]">Ref. total ≈ {usd(refGaveta)}</span>}</p>
                            </Celda>
                            <Celda icon={Landmark} titulo="Por banco">
                                <LineasMonedas lista={bancoKpi} primera="text-[22px] font-semibold" />
                                <p className="mt-2 flex flex-wrap items-baseline gap-x-2 text-[12.5px] leading-snug text-muted">pago móvil, punto, transferencias…{refBanco > 0.004 && <span className="num text-[11.5px]">Ref. total ≈ {usd(refBanco)}</span>}</p>
                            </Celda>
                        </div>
                    </Card>

                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2.5 text-[16px] font-semibold tracking-[-0.01em]"><Store className="size-5 text-muted" aria-hidden />Tu sucursal · {negocio?.nombre_comercial || 'Repuestos de motos'}<span className="text-[13px] font-normal text-muted">{cajas.length} de {cajas.length} cajas abiertas</span></h2>
                        <span className="inline-flex items-center gap-2 rounded-full bg-ok/10 px-3 py-1 text-[12.5px] font-medium text-ok"><span className="size-1.5 animate-pulse rounded-full bg-ok" aria-hidden />En vivo · ahora</span>
                    </div>

                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        {cajas.map((c) => (
                            <Card key={c.id} className={`flex flex-col overflow-hidden ${c.es_mia ? 'ring-1 ring-brand' : ''}`}>
                                <div className="flex flex-wrap items-start justify-between gap-3 px-4 pb-3 pt-4">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand"><ShoppingCart className="size-5" aria-hidden /></span>
                                        <div className="min-w-0">
                                            <p className="flex items-center gap-2 text-[15.5px] font-semibold tracking-[-0.01em]">Caja {c.codigo}
                                                {c.es_mia && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-semibold text-brand">TU CAJA</span>}
                                                {c.ventas === 0 && <span className="rounded-full bg-paper px-2 py-0.5 text-[11px] font-medium text-muted">sin ventas</span>}
                                            </p>
                                            <p className="num truncate text-[13px] text-muted">{c.codigo} · {c.cajero}</p>
                                        </div>
                                    </div>
                                    <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-ok/10 px-3 py-1 text-[12.5px] font-medium text-ok"><span className="size-1.5 rounded-full bg-ok" aria-hidden />Abierta hace {haceTxt(c.fecha_apertura)}</span>
                                </div>
                                <div className="grid grid-cols-3 gap-px bg-line">
                                    <div className="bg-brand-50/40 p-4">
                                        <p className="text-[12.5px] font-medium text-muted">En la gaveta</p>
                                        <LineasMonedas lista={c.gaveta_monedas} />
                                        <p className="mt-1.5 text-[12px] leading-snug text-muted">Efectivo: lo que se cuenta al cerrar</p>
                                    </div>
                                    <div className="bg-white p-4">
                                        <p className="text-[12.5px] font-medium text-muted">Por banco</p>
                                        <LineasMonedas lista={c.banco_monedas} resto="text-[13px] font-semibold" />
                                        <p className="mt-1.5 text-[12px] leading-snug text-muted">Pago móvil, punto, Zelle, Nequi…</p>
                                    </div>
                                    <div className="bg-white p-4">
                                        <p className="text-[12.5px] font-medium text-muted">Ventas</p>
                                        <p className="num mt-1 text-[20px] font-bold leading-none">{n0(c.ventas)}</p>
                                        {c.fondos.length > 0 && (
                                            <div className="mt-1.5 grid gap-0.5">
                                                {c.fondos.map((f) => <p key={f.moneda} className="num text-[12.5px] leading-tight text-muted">Fondo {monto(f.moneda, f.monto)}</p>)}
                                            </div>
                                        )}
                                    </div>
                                </div>
                                <div className="mt-auto flex items-center justify-between gap-2 border-t border-line px-4 py-3">
                                    {esAdmin
                                        ? <a href="/movimientos-caja" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-white px-4 text-[14px] font-semibold hover:bg-paper"><WalletCards className="size-4" />Movimientos</a>
                                        : <span className="text-[13px] text-muted">La cierra quien la abrió</span>}
                                    {c.es_mia
                                        ? <Button onClick={() => router.visit('/caja/cerrar')} className="h-10 px-4"><LockKeyhole className="size-4" />Cerrar caja</Button>
                                        : esAdmin && <a href="/cierre-caja" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-white px-4 text-[14px] font-semibold hover:bg-paper"><Receipt className="size-4" />Su cierre</a>}
                                </div>
                            </Card>
                        ))}
                    </div>
                </>
            )}
        </AppLayout>
    );
}
