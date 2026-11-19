import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AlertTriangle, ArrowRight, Banknote, Check, CreditCard, Landmark, LockKeyhole, Plus, Printer, Receipt, Send, Smartphone, WalletCards } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { Card } from '../../Components/kit';
import { Button } from '../../Components/ui';
import { http } from '../../lib/http';
import { SIMBOLO, n0, n2, usd, bs } from '../../lib/format';
import { imprimir, Ventana } from './Turno';

/* Cerrar caja: arqueo del efectivo moneda por moneda (USD, VES, EUR, COP — cero mezclas,
   la diferencia sale sola) + resumen del turno + pagos que se comparan con el banco.
   Al confirmar se firma el Corte Z: snapshot inmutable con conteo, esperado, diferencia,
   desglose bancario y las tasas del día congeladas. */

const parse = (s) => { const t = String(s ?? '').trim(); if (t === '') return null; const v = Number(t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t); return Number.isNaN(v) ? NaN : v; };
const ORDEN = { USD: 0, VES: 1, EUR: 2, COP: 3 };
const decimalesDe = (m, dec) => dec?.[m] ?? 2;
const formato = (m, v, dec) => (decimalesDe(m, dec) === 0 ? n0(v) : n2(v));
const nombreEfectivo = (m) => `Efectivo en ${m === 'USD' ? 'dólares' : m === 'VES' ? 'bolívares' : m === 'EUR' ? 'euros' : 'pesos colombianos'}`;
const pieMoneda = (m) => (m === 'VES' ? 'billetes' : 'billetes y monedas');
const largo = (iso) => new Intl.DateTimeFormat('es-VE', { dateStyle: 'full', timeStyle: 'short', timeZone: 'America/Caracas' }).format(new Date(iso));
const iconoMetodo = (n) => (/punto/i.test(n) ? CreditCard : /m[oó]vil|movil/i.test(n) ? Smartphone : /zelle|transfer|binance|nequi|bancolombia/i.test(n) ? Send : Landmark);
const tasaTxt = (v) => new Intl.NumberFormat('es-VE', { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(v);

export default function CerrarCaja(p) {
    const { resumen: r, tasas, decimales, usuario, negocio, fecha_larga, tasa_usd, stock_bajo } = p;
    const a = r.apertura;
    const [conteo, setConteo] = useState({});
    const [obs, setObs] = useState('');
    const [env, setEnv] = useState(false);
    const [errores, setErrores] = useState({});
    const [hecho, setHecho] = useState(null);
    const [err, setErr] = useState('');
    const [confirmando, setConfirmando] = useState(false);
    const [declarar, setDeclarar] = useState([]); // monedas dormidas (esperado 0) que el cajero quiere declarar

    // USD y VES siempre visibles (base); EUR y COP cuando tuvieron efectivo, o al pulsar
    // «Declarar efectivo» para reportar un sobrante inesperado.
    const filas = useMemo(() => {
        const visibles = r.monedas.filter((m) => {
            const mon = m.moneda;
            if (['USD', 'VES'].includes(mon)) return true;
            if (Math.abs(Number(m.esperado)) > 0.004 || Number(m.efectivo_recibido) > 0.004) return true;
            return declarar.includes(mon);
        });
        return visibles
            .sort((x, y) => (ORDEN[x.moneda] ?? 9) - (ORDEN[y.moneda] ?? 9))
            .map((m) => {
                const c = parse(conteo[m.moneda]);
                const d = decimalesDe(m.moneda, decimales);
                const dif = c == null || Number.isNaN(c) ? null : Number((c - Number(m.esperado)).toFixed(d === 0 ? 0 : 2));
                return { ...m, contado: c, dif, dec: d };
            });
    }, [r, conteo, declarar, decimales]);
    const ocultas = r.monedas.filter((m) => !['USD', 'VES'].includes(m.moneda) && Math.abs(Number(m.esperado)) <= 0.004 && Number(m.efectivo_recibido) <= 0.004 && !declarar.includes(m.moneda));
    const completo = filas.length > 0 && filas.every((f) => f.contado != null && !Number.isNaN(f.contado) && f.contado >= 0);
    const descuadradas = filas.filter((f) => f.dif !== null && f.dif !== 0);
    const hayDif = descuadradas.length > 0;
    const faltan = filas.filter((f) => f.contado == null || Number.isNaN(f.contado));

    const banco = (r.metodos || []).filter((m) => !m.efectivo);
    const sumPor = (lista, campo) => {
        const por = {};
        lista.forEach((m) => { por[m.moneda] ??= 0; por[m.moneda] += Number(m[campo]); });
        const out = Object.entries(por).filter(([, v]) => Math.abs(v) > 0.004).map(([moneda, monto]) => ({ moneda, monto }));
        out.sort((x, y) => (ORDEN[x.moneda] ?? 9) - (ORDEN[y.moneda] ?? 9));
        return out;
    };
    const ing = sumPor(r.ingresos || [], 'monto');
    const egr = sumPor(r.egresos || [], 'monto');
    const gavetaFinal = r.monedas.filter((m) => Math.abs(Number(m.esperado)) > 0.004).map((m) => ({ moneda: m.moneda, monto: Number(m.esperado) }))
        .sort((x, y) => (ORDEN[x.moneda] ?? 9) - (ORDEN[y.moneda] ?? 9));
    const fondoInicial = r.monedas.filter((m) => Number(m.fondo) > 0.004).map((m) => ({ moneda: m.moneda, monto: Number(m.fondo) }))
        .sort((x, y) => (ORDEN[x.moneda] ?? 9) - (ORDEN[y.moneda] ?? 9));
    const esAdmin = usuario?.rol === 'Admin';

    const enviar = async () => {
        setConfirmando(false);
        if (!completo || env) return;
        setEnv(true); setErr(''); setErrores({});
        try {
            const conteoNum = Object.fromEntries(filas.map((f) => [f.moneda, f.contado]));
            const z = await http('POST', '/cierre-caja', { apertura_id: a.id, conteo: conteoNum, observaciones: obs.trim() || undefined });
            setHecho({ ...z, obs: obs.trim() });
            try { await imprimir({ tipo: 'Z', numero: z.numero, resumen: z.resumen, conteo: z.conteo, diferencia: z.diferencia, cerrado_por: z.cerrado_por, cerrado_en: z.cerrado_en, observaciones: obs.trim() }, `corte-z-${String(z.numero).padStart(6, '0')}.pdf`); } catch { /* el turno quedó cerrado; el botón permite reimprimir */ }
        } catch (x) {
            setErrores(x.errors ? Object.fromEntries(Object.entries(x.errors).map(([k, v]) => [k, v[0]])) : {});
            setErr(x.errors ? '' : x.message);
            setEnv(false);
        }
    };

    if (hecho) {
        const difs = Object.entries(hecho.diferencia ?? {});
        const cuadra = difs.every(([, v]) => Math.abs(v) < 0.005);
        return (
            <AppLayout actual="/caja/cerrar" titulo="Caja cerrada" subtitulo="El turno quedó cerrado y la caja lista para quien venga después." usuario={usuario} negocio={negocio} tasaUsd={tasa_usd} stockBajo={stock_bajo}>
                <Head title="Caja cerrada" />
                <Card className="mx-auto mt-6 max-w-[560px] p-6">
                    <span className={`grid size-12 place-items-center rounded-2xl ${cuadra ? 'bg-ok/10 text-ok' : 'bg-signal/15 text-[#8a5d00]'}`}>{cuadra ? <Check className="size-6" /> : <AlertTriangle className="size-6" />}</span>
                    <h2 className="mt-4 text-xl font-semibold tracking-[-0.01em]">Corte Z N.º {String(hecho.numero).padStart(6, '0')} hecho</h2>
                    <p className={`mt-1.5 text-[14.5px] font-medium ${cuadra ? 'text-ok' : 'text-[#8a5d00]'}`}>{cuadra ? 'El efectivo cuadra con lo esperado, moneda por moneda.' : 'Hay diferencias en el efectivo contado.'}</p>
                    <dl className="mt-4 space-y-2">
                        {difs.map(([m, v]) => (
                            <div key={m} className="flex items-center justify-between gap-3 rounded-lg border border-line px-3.5 py-2.5 text-[14px]">
                                <dt className="font-medium">Diferencia {m}</dt>
                                <dd className={`num font-semibold ${Math.abs(v) < 0.005 ? 'text-ok' : v > 0 ? 'text-[#8a5d00]' : 'text-danger'}`}>{Math.abs(v) < 0.005 ? 'Cuadra' : `${v > 0 ? 'Sobra' : 'Falta'} ${formato(m, Math.abs(v), decimales)}`}</dd>
                            </div>
                        ))}
                        {hecho.obs && <p className="text-[13px] text-muted">Nota del cierre: {hecho.obs}</p>}
                    </dl>
                    <div className="mt-5 grid gap-2 sm:grid-cols-2">
                        <button type="button" onClick={() => imprimir({ tipo: 'Z', numero: hecho.numero, resumen: hecho.resumen, conteo: hecho.conteo, diferencia: hecho.diferencia, cerrado_por: hecho.cerrado_por, cerrado_en: hecho.cerrado_en, observaciones: hecho.obs }, `corte-z-${String(hecho.numero).padStart(6, '0')}.pdf`)} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-line px-4 text-[14.5px] font-semibold hover:bg-paper"><Printer className="size-4" />Reimprimir ticket</button>
                        <Button type="button" onClick={() => router.visit('/cierre-caja')} className="h-11">Ver cierres anteriores<ArrowRight className="size-4" /></Button>
                    </div>
                    <button type="button" onClick={() => router.visit('/caja')} className="mt-2 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg text-[14.5px] font-semibold text-brand hover:bg-brand-50"><Receipt className="size-4" />Ir al punto de venta</button>
                </Card>
            </AppLayout>
        );
    }

    return (
        <AppLayout actual="/caja/cerrar" titulo="Cerrar caja" subtitulo={`${negocio?.nombre_comercial || 'Repuestos de motos'} · abierta ${largo(a.fecha_apertura)} · ${n0(r.ventas)} ${r.ventas === 1 ? 'venta' : 'ventas'}`} usuario={usuario} negocio={negocio} tasaUsd={tasa_usd} stockBajo={stock_bajo}>
            <Head title="Cerrar caja" />
            <p className="mb-1 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.14em] text-muted"><span className="inline-block h-px w-6 bg-line" aria-hidden />Dinero · Caja</p>
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <h1 className="text-[26px] font-bold tracking-[-0.02em]">Cerrar caja</h1>
                {esAdmin && <a href="/movimientos-caja" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-white px-3.5 text-[14px] font-semibold hover:bg-paper"><WalletCards className="size-4" />Ver movimientos</a>}
            </div>
            <form onSubmit={(e) => { e.preventDefault(); if (hayDif) { setConfirmando(true); } else { enviar(); } }} className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1fr_400px]">
                <Card>
                    <div className="border-b border-line px-5 py-4">
                        <h2 className="text-[16px] font-semibold tracking-[-0.01em]">Cuenta lo que hay en la gaveta</h2>
                        <p className="mt-0.5 text-[13.5px] text-muted">Escribe lo contado; la diferencia sale sola. Cada moneda cuadra por separado.</p>
                    </div>
                    <div className="divide-y divide-line">
                        {filas.map((f) => (
                            <div key={f.moneda} className="grid grid-cols-2 items-end gap-3 px-5 py-4 sm:grid-cols-[1.2fr_1fr_1.1fr_auto]">
                                <div>
                                    <p className="text-[14.5px] font-semibold leading-tight">{nombreEfectivo(f.moneda)}</p>
                                    <p className="text-[12.5px] text-muted">{pieMoneda(f.moneda)}</p>
                                </div>
                                <div>
                                    <p className="text-[12.5px] font-medium text-muted">Debería haber</p>
                                    <p className="num mt-0.5 text-[18px] font-bold">{formato(f.moneda, f.esperado, decimales)}</p>
                                </div>
                                <div>
                                    <label htmlFor={`c-${f.moneda}`} className="text-[12.5px] font-medium text-muted">Contaste</label>
                                    <div className="relative mt-0.5">
                                        <span className="num pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-muted">{SIMBOLO[f.moneda] || f.moneda}</span>
                                        <input id={`c-${f.moneda}`} inputMode="decimal" autoComplete="off" value={conteo[f.moneda] ?? ''} onChange={(e) => { setConteo({ ...conteo, [f.moneda]: e.target.value }); setErrores({}); }}
                                            placeholder={f.dec === 0 ? '0' : '0,00'} aria-describedby={`c-${f.moneda}-h`}
                                            className="num h-12 w-full rounded-lg border border-line bg-white pl-8 pr-3 text-right text-[16px] font-semibold placeholder:text-[#9aa4af] focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand" />
                                    </div>
                                </div>
                                <div className="sm:pb-1">
                                    <p className="text-[12.5px] font-medium text-muted">Diferencia</p>
                                    {f.dif == null
                                        ? <p id={`c-${f.moneda}-h`} className="num mt-0.5 text-[14px] text-muted">—</p>
                                        : f.dif === 0
                                            ? <p className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-ok/10 px-3 py-1 text-[13px] font-semibold text-ok"><Check className="size-3.5" />Cuadra</p>
                                            : <p id={`c-${f.moneda}-h`} className={`num mt-0.5 text-[14px] font-semibold ${f.dif > 0 ? 'text-[#8a5d00]' : 'text-danger'}`}>{f.dif > 0 ? 'Sobran ' : 'Faltan '}{formato(f.moneda, Math.abs(f.dif), decimales)}</p>}
                                </div>
                            </div>
                        ))}
                    </div>
                    {ocultas.length > 0 && (
                        <div className="border-t border-line px-5 py-3">
                            <button type="button" onClick={() => setDeclarar(ocultas.map((m) => m.moneda))} className="inline-flex h-9 items-center gap-2 rounded-lg border border-dashed border-line px-3.5 text-[13.5px] font-semibold text-muted hover:border-brand hover:text-brand">
                                <Plus className="size-4" />Declarar efectivo en {ocultas.map((m) => m.moneda).join(' / ')} (apareció un billete)
                            </button>
                        </div>
                    )}
                    <div className="border-t border-line px-5 py-4">
                        <label htmlFor="obs" className="text-[13.5px] font-medium">Nota del cierre <span className="font-normal text-muted">obligatoria si alguna moneda no cuadra</span></label>
                        <textarea id="obs" rows={2} maxLength={500} value={obs} onChange={(e) => setObs(e.target.value)} placeholder="Ej.: se dio vuelto de más en una venta o se cambió efectivo"
                            className="mt-1.5 w-full resize-none rounded-lg border border-line bg-white px-3.5 py-2.5 text-[14.5px] placeholder:text-[#9aa4af] focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand" />
                        {errores.observaciones && <p role="alert" className="mt-2 flex items-start gap-1.5 text-[13px] text-danger"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{errores.observaciones}</p>}
                        {errores.conteo && <p role="alert" className="mt-2 flex items-start gap-1.5 text-[13px] text-danger"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{errores.conteo}</p>}
                        {errores.apertura_id && <p role="alert" className="mt-2 flex items-start gap-1.5 text-[13px] text-danger"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{errores.apertura_id}</p>}
                        {err && <p role="alert" className="mt-2 flex items-start gap-1.5 text-[13px] text-danger"><AlertTriangle className="mt-0.5 size-3.5 shrink-0" />{err}</p>}
                    </div>
                </Card>

                <div className="grid gap-4">
                    <Card>
                        <h2 className="border-b border-line px-5 py-4 text-[16px] font-semibold tracking-[-0.01em]">El turno</h2>
                        <dl className="px-5 py-3">
                            <div className="flex items-start justify-between gap-3 py-2">
                                <dt className="text-[14px]">Fondo inicial</dt>
                                <dd className="text-right"><LineaMoneda lista={fondoInicial} fuerte={false} grande /></dd>
                            </div>
                            <div className="flex items-start justify-between gap-3 py-2">
                                <dt className="text-[14px]">+ Ventas <span className="text-[12.5px] text-muted">({n0(r.ventas)})</span></dt>
                                <dd className="num text-right text-[14px] font-semibold">{usd(r.total_usd)}<span className="block text-[12.5px] font-normal text-muted">{bs(r.total_ves)} · con IGTF</span></dd>
                            </div>
                            {(ing.length > 0 || (r.ingresos || []).length > 0) && (
                                <div className="flex items-start justify-between gap-3 py-2">
                                    <dt className="text-[14px]">+ Otros ingresos <span className="text-[12.5px] text-muted">({n0((r.ingresos || []).length)})</span></dt>
                                    <dd className="text-right">{ing.length > 0 ? <LineaMoneda lista={ing} /> : <span className="num text-[14px] font-semibold">—</span>}</dd>
                                </div>
                            )}
                            {egr.length > 0 && (
                                <div className="flex items-start justify-between gap-3 py-2">
                                    <dt className="text-[14px]">− Egresos <span className="text-[12.5px] text-muted">({n0((r.egresos || []).length)})</span></dt>
                                    <dd className="text-right"><LineaMoneda lista={egr} /></dd>
                                </div>
                            )}
                            <div className="mt-1 flex items-start justify-between gap-3 border-t border-line pt-3 pb-1">
                                <dt className="text-[14.5px] font-semibold">Entró en total <span className="block text-[12px] font-normal text-muted">efectivo que debe haber en gaveta, por moneda</span></dt>
                                <dd className="text-right"><LineaMoneda lista={gavetaFinal} fuerte /></dd>
                            </div>
                        </dl>
                        <div className="border-t border-line px-5 py-4">
                            <p className="flex items-start gap-2 text-[13px] text-muted"><Landmark className="mt-0.5 size-4 shrink-0" />No van a la gaveta: se comparan con el banco.</p>
                            {banco.length === 0 ? <p className="num mt-2 text-[13.5px] text-muted">No hubo pagos electrónicos en este turno.</p> : (
                                <ul className="mt-2 divide-y divide-line">
                                    {banco.map((m, i) => {
                                        const Ic = iconoMetodo(m.metodo);
                                        return (
                                            <li key={`${m.metodo}-${m.moneda}-${i}`} className="flex items-center justify-between gap-3 py-2 text-[14px]">
                                                <span className="flex min-w-0 items-center gap-2.5"><Ic className="size-4 shrink-0 text-muted" aria-hidden /><span className="min-w-0 truncate font-medium">{m.metodo}</span><span className="num rounded-full bg-paper px-2 py-0.5 text-[11.5px] text-muted">{m.n}</span></span>
                                                <span className="num shrink-0 text-right font-semibold">{m.moneda === 'USD' ? usd(m.cobrado) : m.moneda === 'COP' ? `COP ${n0(m.cobrado)}` : m.moneda === 'EUR' ? `€ ${n2(m.cobrado)}` : bs(m.cobrado)}<span className="block text-[12px] font-normal text-muted">equiv. {usd(m.usd)}</span></span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                        {tasas && Object.keys(tasas).length > 0 && (
                            <div className="border-t border-line px-5 py-3">
                                <p className="num flex flex-wrap gap-x-3 gap-y-1 text-[12px] text-muted">
                                    {['USD', 'EUR', 'COP'].filter((m) => tasas[m]).map((m) => (
                                        <span key={m}>{m === 'COP' ? 'Tasa COP' : `BCV ${m}`} {tasaTxt(tasas[m].tasa)}</span>
                                    ))}
                                    <span className="text-[11.5px]">· se congelan en el historial del cierre</span>
                                </p>
                            </div>
                        )}
                    </Card>
                    <div className="grid gap-2">
                        <Button type="submit" disabled={!completo || env || (hayDif && !obs.trim())} className="h-12 text-[15.5px]"><LockKeyhole className="size-4" />{env ? 'Cerrando…' : 'Cerrar caja'}</Button>
                        {hayDif && !obs.trim() && <p className="text-center text-[12.5px] text-[#8a5d00]">Hay diferencias en {descuadradas.map((f) => f.moneda).join(', ')}: escribe la nota del cierre para poder continuar.</p>}
                        {faltan.length > 0 && !completo && <p className="text-center text-[12.5px] text-muted">Falta contar: {faltan.map((f) => f.moneda).join(', ')} (puede ser 0).</p>}
                        <button type="button" onClick={() => router.visit('/caja')} className="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-line bg-white text-[14.5px] font-semibold hover:bg-paper"><Banknote className="size-4" />Seguir vendiendo</button>
                    </div>
                </div>
            </form>

            {confirmando && (
                <Ventana titulo="Cerrar caja con diferencias" subtitulo="Corte Z con descuadre declarado" cerrar={() => setConfirmando(false)} ancho="max-w-[480px]"
                    pie={<><button type="button" onClick={() => setConfirmando(false)} className="inline-flex h-11 items-center rounded-lg border border-line px-4 text-[14.5px] font-semibold hover:bg-paper">Volver a contar</button>
                        <Button type="button" onClick={enviar} className="h-11"><LockKeyhole className="size-4" />Cerrar de todas formas</Button></>}>
                    <p className="flex items-start gap-2 text-[14.5px] font-medium text-[#8a5d00]"><AlertTriangle className="mt-0.5 size-4 shrink-0" />Estas monedas no cuadran:</p>
                    <dl className="mt-3">{descuadradas.map((f) => (
                        <div key={f.moneda} className="flex items-center justify-between gap-3 rounded-lg border border-line px-3.5 py-2.5 text-[14px]">
                            <dt className="font-medium">{f.moneda}</dt>
                            <dd className={`num font-semibold ${f.dif > 0 ? 'text-[#8a5d00]' : 'text-danger'}`}>{f.dif > 0 ? 'Sobran ' : 'Faltan '}{formato(f.moneda, Math.abs(f.dif), decimales)}</dd>
                        </div>
                    ))}</dl>
                    <p className="mt-3 text-[13px] text-muted">La diferencia queda fija en el historial con tu nota. No se compensan divisas: cada moneda cuadra por separado.</p>
                </Ventana>
            )}
        </AppLayout>
    );
}

/* Una línea con montos por moneda, cada divisa en su formato nativo. */
function LineaMoneda({ lista, fuerte = false, grande = false }) {
    if (!lista || lista.length === 0) return <span className="num text-[14px] font-semibold text-muted">—</span>;
    return (
        <span className="grid gap-0.5">
            {lista.map((x, i) => (
                <span key={x.moneda} className={`num leading-tight ${i === 0 ? (fuerte ? 'text-[17px] font-bold' : grande ? 'text-[14px] font-semibold' : 'text-[14px] font-semibold') : 'text-[12.5px] font-semibold text-muted'}`}>
                    {x.moneda === 'USD' ? usd(x.monto) : x.moneda === 'VES' ? bs(x.monto) : x.moneda === 'COP' ? `COP ${n0(x.monto)}` : `€ ${n2(x.monto)}`}
                </span>
            ))}
        </span>
    );
}
