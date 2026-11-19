import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, ArrowDownLeft, ArrowUpRight, Banknote, Check, PiggyBank, Receipt } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { Card, Vacio } from '../../Components/kit';
import { Button, Field } from '../../Components/ui';
import { http } from '../../lib/http';
import { SIMBOLO, n0, n2, usd, bs } from '../../lib/format';

/* Caja chica: los gastos menores y entradas de efectivo del día (fletes, retiros, changes),
   fuera de las ventas. Cada movimiento queda atado al turno de quien lo registra y entra
   al arqueo de su moneda: son los «Otros ingresos» y «Egresos» de la pantalla Cerrar caja. */

const montoTxt = (m, v) => (m === 'USD' ? usd(v) : m === 'VES' ? bs(v) : m === 'COP' ? `COP ${n0(v)}` : `€ ${n2(v)}`);
const ORDEN = { USD: 0, VES: 1, EUR: 2, COP: 3 };

export default function CajaChica(p) {
    const { resumen, movimientos, monedas, usuario, negocio, fecha_larga, tasa_usd, stock_bajo } = p;
    const [form, setForm] = useState({ tipo: 'EGRESO', concepto: '', monto: '', moneda: 'USD', referencia: '' });
    const [errores, setErrores] = useState({});
    const [msg, setMsg] = useState('');
    const [env, setEnv] = useState(false);
    const [anular, setAnular] = useState(null); // movimiento a anular
    const [motivo, setMotivo] = useState('');

    const guardar = async (e) => {
        e.preventDefault();
        if (env) return;
        setEnv(true); setErrores({}); setMsg('');
        try {
            await http('POST', '/movimientos-caja', { ...form, monto: form.monto.trim() });
            setForm({ ...form, concepto: '', monto: '', referencia: '' });
            setMsg('ok');
            router.reload({ only: ['resumen', 'movimientos'], preserveScroll: true });
        } catch (x) {
            setErrores(x.errors ? Object.fromEntries(Object.entries(x.errors).map(([k, v]) => [k, v[0]])) : {});
            if (!x.errors) setErrores({ concepto: x.message });
        } finally {
            setEnv(false);
        }
    };

    const confirmarAnular = async () => {
        if (!anular || motivo.trim().length < 3) return;
        try {
            await http('POST', `/movimientos-caja/${anular.id}/anular`, { motivo: motivo.trim() });
            setAnular(null); setMotivo('');
            router.reload({ only: ['resumen', 'movimientos'], preserveScroll: true });
        } catch (x) {
            setAnular(null); setMotivo('');
            setErrores({ concepto: x.message });
        }
    };

    const por = (lista) => lista.filter((x) => x.monto > 0.004).sort((x, y) => (ORDEN[x.moneda] ?? 9) - (ORDEN[y.moneda] ?? 9));
    const ingresos = por(resumen.map((x) => ({ moneda: x.moneda, monto: x.ingresos })));
    const egresos = por(resumen.map((x) => ({ moneda: x.moneda, monto: x.egresos })));

    return (
        <AppLayout actual="/caja-chica" titulo="Caja chica" subtitulo="Gastos menores y entradas de efectivo del día, fuera de las ventas." usuario={usuario} negocio={negocio} tasaUsd={tasa_usd} stockBajo={stock_bajo}>
            <Head title="Caja chica" />
            <p className="mb-1 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.14em] text-muted"><span className="inline-block h-px w-6 bg-line" aria-hidden />Dinero · Caja</p>
            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <h1 className="text-[26px] font-bold tracking-[-0.02em]">Caja chica</h1>
                <p className="text-[13.5px] text-muted">{fecha_larga}</p>
            </div>

            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line bg-line sm:grid-cols-3">
                <div className="bg-white p-4">
                    <p className="flex items-center gap-2 text-[13px] font-medium text-muted"><ArrowUpRight className="size-4 text-ok" aria-hidden />Entradas de hoy</p>
                    <div className="mt-2 grid gap-0.5">
                        {ingresos.length ? ingresos.map((x) => <p key={x.moneda} className="num text-[17px] font-bold leading-tight text-ok">{montoTxt(x.moneda, x.monto)}</p>) : <p className="num text-[15px] text-muted">—</p>}
                    </div>
                </div>
                <div className="bg-white p-4">
                    <p className="flex items-center gap-2 text-[13px] font-medium text-muted"><ArrowDownLeft className="size-4 text-danger" aria-hidden />Salidas de hoy</p>
                    <div className="mt-2 grid gap-0.5">
                        {egresos.length ? egresos.map((x) => <p key={x.moneda} className="num text-[17px] font-bold leading-tight text-danger">{montoTxt(x.moneda, x.monto)}</p>) : <p className="num text-[15px] text-muted">—</p>}
                    </div>
                </div>
                <div className="col-span-2 bg-white p-4 sm:col-span-1">
                    <p className="flex items-center gap-2 text-[13px] font-medium text-muted"><PiggyBank className="size-4" aria-hidden />Impacto en el arqueo</p>
                    <p className="mt-2 text-[13px] leading-snug text-muted">Cada registro entra a la gaveta de su moneda y aparece en <b className="font-semibold text-text">Cerrar caja</b> como «Otros ingresos» o «Egresos». Cada moneda cuadra por separado.</p>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-[400px_1fr]">
                <Card>
                    <h2 className="border-b border-line px-5 py-4 text-[16px] font-semibold tracking-[-0.01em]">Registro rápido</h2>
                    <form onSubmit={guardar} className="grid gap-3 px-5 py-4">
                        <div role="radiogroup" aria-label="Tipo de movimiento" className="grid grid-cols-2 gap-2">
                            {[['EGRESO', 'Salida de caja', ArrowDownLeft], ['INGRESO', 'Entrada a caja', ArrowUpRight]].map(([v, l, Ic]) => (
                                <button key={v} type="button" role="radio" aria-checked={form.tipo === v} onClick={() => setForm({ ...form, tipo: v })}
                                    className={`flex items-center justify-center gap-1.5 rounded-xl border px-3 py-2.5 text-[14px] font-semibold ${form.tipo === v ? 'border-brand bg-brand-50 text-brand' : 'border-line text-text hover:border-[#b9c1ca] hover:bg-paper'}`}>
                                    <Ic className="size-4 shrink-0" aria-hidden />{l}
                                </button>
                            ))}
                        </div>
                        <Field label="Concepto" icon={Receipt} value={form.concepto} error={errores.concepto} placeholder="Ej.: flete del proveedor" onChange={(e) => setForm({ ...form, concepto: e.target.value })} maxLength={200} required />
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Moneda" select value={form.moneda} onChange={(e) => setForm({ ...form, moneda: e.target.value })}>
                                {monedas.map((m) => <option key={m} value={m}>{m}</option>)}
                            </Field>
                            <Field label="Monto" icon={Banknote} inputMode="decimal" value={form.monto} error={errores.monto} placeholder={form.moneda === 'COP' ? '0' : '0,00'} onChange={(e) => setForm({ ...form, monto: e.target.value })} required />
                        </div>
                        <Field label="Referencia (opcional)" value={form.referencia} placeholder="N.º de factura o recibo" onChange={(e) => setForm({ ...form, referencia: e.target.value })} maxLength={60} />
                        {msg === 'ok' && <p className="flex items-center gap-1.5 text-[13px] font-medium text-ok"><Check className="size-3.5" />Movimiento registrado en el turno abierto.</p>}
                        <Button type="submit" disabled={env} className="h-11">{env ? 'Guardando…' : form.tipo === 'EGRESO' ? 'Registrar salida' : 'Registrar entrada'}</Button>
                    </form>
                </Card>

                <Card>
                    <h2 className="border-b border-line px-5 py-4 text-[16px] font-semibold tracking-[-0.01em]">Movimientos de hoy <span className="text-[13px] font-normal text-muted">({n0(movimientos.length)})</span></h2>
                    {movimientos.length === 0 ? (
                        <div className="p-6"><Vacio icon={PiggyBank} titulo="Sin movimientos de caja chica hoy" texto="Registra el primer gasto menor o entrada de efectivo con el formulario de la izquierda." /></div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[560px] text-[14px]">
                                <thead><tr className="border-b border-line text-left text-[12px] uppercase tracking-wide text-muted">
                                    <th className="px-5 py-2.5 font-semibold">Concepto</th><th className="px-3 py-2.5 font-semibold">Tipo</th>
                                    <th className="px-3 py-2.5 text-right font-semibold">Monto</th><th className="px-3 py-2.5 font-semibold">Por</th>
                                    <th className="px-3 py-2.5 font-semibold">Estado</th><th className="px-5 py-2.5" />
                                </tr></thead>
                                <tbody className="divide-y divide-line">
                                    {movimientos.map((m) => (
                                        <tr key={m.id} className={m.estado === 'ANULADO' ? 'text-muted' : ''}>
                                            <td className="px-5 py-2.5">
                                                <p className={`font-medium ${m.estado === 'ANULADO' ? 'line-through' : ''}`}>{m.concepto}</p>
                                                {m.referencia && <p className="num text-[12px] text-muted">Ref. {m.referencia}</p>}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-semibold ${m.tipo === 'INGRESO' ? 'bg-ok/10 text-ok' : 'bg-danger/10 text-danger'}`}>
                                                    {m.tipo === 'INGRESO' ? <ArrowUpRight className="size-3.5" /> : <ArrowDownLeft className="size-3.5" />}{m.tipo === 'INGRESO' ? 'Entrada' : 'Salida'}
                                                </span>
                                            </td>
                                            <td className={`num px-3 py-2.5 text-right font-semibold ${m.estado === 'ANULADO' ? 'text-muted line-through' : ''}`}>{montoTxt(m.moneda, m.monto)}</td>
                                            <td className="px-3 py-2.5 text-[13px] text-muted">{m.por} · <span className="num">{m.cuando}</span></td>
                                            <td className="px-3 py-2.5">
                                                {m.estado === 'ACTIVO' ? <span className="text-[12.5px] font-medium text-ok">Activo</span>
                                                    : <span title={m.motivo} className="text-[12.5px] font-medium text-danger">Anulado</span>}
                                            </td>
                                            <td className="px-5 py-2.5 text-right">
                                                {m.estado === 'ACTIVO' && <button onClick={() => setAnular(m)} className="text-[13px] font-semibold text-brand hover:underline">Anular</button>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>

            {anular && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-[rgba(15,23,42,0.45)] p-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-[440px] rounded-2xl border border-line bg-white p-6 shadow-xl">
                        <p className="flex items-start gap-2 text-[15px] font-semibold"><AlertTriangle className="mt-0.5 size-4 text-[#8a5d00]" />Anular «{anular.concepto}»</p>
                        <p className="mt-1.5 text-[13.5px] text-muted">El movimiento queda marcado como anulado con su motivo; no se borra nada del historial.</p>
                        <textarea rows={2} value={motivo} onChange={(e) => setMotivo(e.target.value)} placeholder="Motivo de la anulación (mínimo 3 caracteres)"
                            className="mt-3 w-full resize-none rounded-lg border border-line px-3.5 py-2.5 text-[14.5px] placeholder:text-[#9aa4af] focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand" />
                        <div className="mt-4 flex justify-end gap-2">
                            <button onClick={() => { setAnular(null); setMotivo(''); }} className="inline-flex h-10 items-center rounded-lg border border-line px-4 text-[14px] font-semibold hover:bg-paper">Cancelar</button>
                            <Button onClick={confirmarAnular} disabled={motivo.trim().length < 3} className="h-10">Anular movimiento</Button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
