import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, Award, CheckCircle2, CloudCog, Download, FileText, PlugZap, Printer, RefreshCw, Send, ShieldCheck, TriangleAlert } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { Card, Kpi, Toast } from '../../Components/kit';
import { Button, Choice, Field, Segmented } from '../../Components/ui';
import { http } from '../../lib/http';
import { n2 } from '../../lib/format';

const AMBIENTES = [
    { value: 'DEMO', label: 'Demo', note: 'Para probar sin validez legal', icon: CloudCog },
    { value: 'PRODUCCION', label: 'Producción', note: 'Documentos legales reales', icon: Award },
];

const MODOS = [
    { value: 'IMPRENTA_DIGITAL', label: 'Imprenta digital', note: 'TFHKA emite por API y asigna el número de control', icon: CloudCog },
    { value: 'MAQUINA_FISCAL', label: 'Máquina fiscal', note: 'La impresora autorizada imprime y asigna el control', icon: Printer },
    { value: 'MANUAL', label: 'Numeración local', note: 'Correlativo propio del negocio, número a mano', icon: FileText },
];

const PROTOCOLOS = [
    { value: 'TCP', label: 'TCP directo', note: 'Socket de la máquina (típ. 9100)' },
    { value: 'HTTP', label: 'Driver HTTP', note: 'Agente local del proveedor' },
    { value: 'SIMULADOR', label: 'Simulador', note: 'Asigna el número sin hardware (pruebas)' },
];

const MONEDAS = [
    { value: 'BSD', label: 'Bolívar (BSD)' },
    { value: 'USD', label: 'Dólar (USD)' },
    { value: 'EUR', label: 'Euro (EUR)' },
];

const ESTADO = {
    EMITIDA: 'bg-[#e7f6ee] text-ok',
    ERROR: 'bg-[#fdecec] text-danger',
    PENDIENTE: 'bg-[#fff8e6] text-[#8a5a00]',
    ANULADA: 'bg-paper text-muted',
};

const Etiqueta = ({ estado }) => (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${ESTADO[estado] || ESTADO.PENDIENTE}`}>
        {estado || 'PENDIENTE'}
    </span>
);

const Aviso = ({ tono = 'info', icon: Icon = ShieldCheck, children }) => (
    <div className={`flex gap-3 rounded-xl border px-4 py-3 text-[14px] leading-snug ${tono === 'warn' ? 'border-[#f0d58a] bg-[#fff8e6] text-[#6f4b00]' : tono === 'bad' ? 'border-[#f3c2c2] bg-[#fdf1f1] text-[#8a1f1f]' : 'border-[#cfdcf8] bg-brand-50/60 text-[#233a74]'}`}>
        <Icon className="mt-0.5 size-[18px] shrink-0" /><div>{children}</div>
    </div>
);

function Tarjeta({ icon: Icon, titulo, texto, children, pie }) {
    return (
        <Card className="overflow-hidden">
            <header className="flex items-start gap-4 border-b border-line px-6 py-5">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand"><Icon className="size-5" /></span>
                <div><h2 className="text-[17px] font-semibold tracking-[-0.01em]">{titulo}</h2><p className="mt-0.5 max-w-[64ch] text-[14px] leading-snug text-muted">{texto}</p></div>
            </header>
            <div className="space-y-5 px-6 py-6">{children}</div>
            {pie && <footer className="border-t border-line bg-paper/60 px-6 py-3.5 text-[13px] text-muted">{pie}</footer>}
        </Card>
    );
}

export default function FacturacionDigital({ config, resumen, pendientes, notas_pendientes, eventos, metodos, negocio, usuario, ...resto }) {
    const [c, setC] = useState({
        activo: !!config.activo,
        ambiente: config.ambiente || 'DEMO',
        base_url: config.base_url || '',
        usuario: config.usuario || '',
        clave: '',
        serie: config.serie || '',
        sucursal: config.sucursal || '',
        codigo_ciiu: config.codigo_ciiu || '4663',
        codigo_plu_default: config.codigo_plu_default || '',
        usar_datos_negocio: config.usar_datos_negocio !== false,
        auto_emitir: config.auto_emitir !== false,
        timeout_segundos: config.timeout_segundos || 60,
        moneda: config.moneda || 'BSD',
        regimen_esp_tributacion: config.regimen_esp_tributacion || '',
        modo: config.modo || 'IMPRENTA_DIGITAL',
        maquina_registro: config.maquina?.maquina_registro || '',
        maquina_ip: config.maquina?.maquina_ip || '',
        maquina_puerto: config.maquina?.maquina_puerto || 9100,
        maquina_protocolo: config.maquina?.maquina_protocolo || 'TCP',
        punto_emision: config.maquina?.punto_emision || '',
        oficina: config.maquina?.oficina || '',
    });
    const [datos, setDatos] = useState({ config, resumen, pendientes, notas_pendientes, eventos, metodos });
    const cfg = datos.config || config;
    const [aviso, setAviso] = useState('');
    const [error, setError] = useState('');
    const [env, setEnv] = useState(false);
    const [probando, setProbando] = useState(false);
    const [ocupado, setOcupado] = useState(null);

    const set = (k, v) => setC((s) => ({ ...s, [k]: v }));

    const aplicar = (r) => {
        if (r?.config) {
            const { message, resultado, ...estado } = r;
            setDatos(estado);
            setC((s) => ({
                ...s,
                ...Object.fromEntries(Object.entries(estado.config).filter(([k]) => ['activo', 'ambiente', 'base_url', 'usuario', 'serie', 'sucursal', 'codigo_ciiu', 'codigo_plu_default', 'usar_datos_negocio', 'auto_emitir', 'timeout_segundos', 'moneda', 'regimen_esp_tributacion', 'modo'].includes(k))),
                maquina_registro: estado.config.maquina?.maquina_registro || '',
                maquina_ip: estado.config.maquina?.maquina_ip || '',
                maquina_puerto: estado.config.maquina?.maquina_puerto || 9100,
                maquina_protocolo: estado.config.maquina?.maquina_protocolo || 'TCP',
                punto_emision: estado.config.maquina?.punto_emision || '',
                oficina: estado.config.maquina?.oficina || '',
                clave: '',
            }));
        }
        if (r?.message) setAviso(r.message);
    };

    const fallo = (e) => {
        setAviso('');
        setError(e?.message || 'No se pudo completar la acción.');
    };

    const guardar = async (e) => {
        e?.preventDefault?.();
        setError(''); setAviso('');
        if (c.modo === 'IMPRENTA_DIGITAL' && !c.usuario.trim()) { setError('Escribe el usuario que te entregó TFHKA.'); return; }
        if (c.modo === 'IMPRENTA_DIGITAL' && !c.clave.trim() && !cfg.tiene_clave) { setError('Escribe la clave que te entregó TFHKA.'); return; }
        if (c.modo === 'MAQUINA_FISCAL' && c.maquina_protocolo !== 'SIMULADOR' && !c.maquina_ip.trim()) { setError('Indica la dirección (IP/host) de la máquina fiscal o de su driver.'); return; }
        setEnv(true);
        try { aplicar(await http('POST', '/facturacion-digital', { ...c, timeout_segundos: Number(c.timeout_segundos) || 60, maquina_puerto: Number(c.maquina_puerto) || 9100 })); }
        catch (e2) { fallo(e2); } finally { setEnv(false); }
    };

    const probar = async () => {
        setError(''); setAviso(''); setProbando(true);
        try { aplicar(await http('POST', '/facturacion-digital/probar')); }
        catch (e) { fallo(e); } finally { setProbando(false); }
    };

    const accion = async (clave, metodo, url, cuerpo) => {
        setError(''); setAviso(''); setOcupado(clave);
        try { aplicar(await http(metodo, url, cuerpo)); }
        catch (e) { fallo(e); } finally { setOcupado(null); }
    };

    const anularNota = (id) => {
        const motivo = window.prompt('Motivo de la anulación (queda en TFHKA):');
        if (!motivo || motivo.trim().length < 3) return;
        accion(`anular-${id}`, 'POST', `/facturacion-digital/notas/${id}/anular`, { motivo: motivo.trim() });
    };

    const r = datos.resumen || {};
    const pendientesV = datos.pendientes || [];
    const pendientesN = datos.notas_pendientes || [];
    const eventosV = datos.eventos || [];
    const metodosV = datos.metodos || [];

    return (
        <AppLayout actual="/facturacion-digital" titulo="Facturación digital" subtitulo="The Factory HKA · documentos legales de SENIAT" usuario={usuario} negocio={negocio} tasaUsd={resto.tasa_usd} stockBajo={resto.stock_bajo}>
            <Head title="Facturación digital" />
            <Toast texto={aviso} />

            <div className="space-y-5">
                {!cfg.tabla_lista && (
                    <Aviso tono="bad" icon={TriangleAlert}>
                        <strong>Falta un paso en la base de datos.</strong> Ejecuta <code>database/sql/40_tfhka_facturacion.sql</code> para crear las tablas y columnas fiscales. Hasta entonces no se pueden guardar credenciales ni emitir.
                    </Aviso>
                )}

                {cfg.activo && !cfg.tiene_clave && (
                    <Aviso tono="warn" icon={AlertTriangle}>La facturación digital está activa pero no tiene clave guardada: no podrá autenticarse con TFHKA.</Aviso>
                )}

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi icon={CheckCircle2} titulo="Facturas emitidas" valor={r.emitidas ?? 0} detalle="Confirmadas por TFHKA" />
                    <Kpi icon={Clock} titulo="Por emitir" valor={r.pendientes ?? 0} detalle="Ventas completadas sin factura fiscal" tono="warn" />
                    <Kpi icon={TriangleAlert} titulo="Con error" valor={r.errores ?? 0} detalle="Revisa el mensaje y reintenta" tono={r.errores ? 'bad' : undefined} />
                    <Kpi icon={FileText} titulo="Notas fiscales" valor={r.notas_emitidas ?? 0} detalle={`${r.notas_pendientes ?? 0} sin confirmar`} />
                </div>

                <div className="grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
                    <Tarjeta icon={PlugZap} titulo="Credenciales y emisión" texto="Cada tienda usa sus propias credenciales. La clave se guarda cifrada con la llave de la aplicación y nunca vuelve al navegador."
                        pie={cfg.ultima_prueba_en ? `Última prueba: ${cfg.ultima_prueba_en} · ${cfg.ultima_prueba_ok ? 'correcta' : 'falló'}${cfg.ultima_prueba_mensaje ? ` — ${cfg.ultima_prueba_mensaje}` : ''}` : 'Aún no has probado la conexión.'}>
                        <form onSubmit={guardar} className="space-y-5">
                            <Choice checked={c.activo} onChange={(v) => set('activo', v)} title="Usar facturación fiscal en esta tienda">
                                Imprenta digital: se emite la factura legal en TFHKA. Máquina fiscal: se imprime en tu impresora autorizada. Numeración local: correlativo propio con número a mano.
                            </Choice>

                            <div>
                                <p className="mb-2 text-[13.5px] font-medium text-muted">Modo de emisión fiscal</p>
                                <Segmented value={c.modo} onChange={(v) => set('modo', v)} options={MODOS} name="Modo de emisión" />
                                {c.modo === 'MANUAL' && <p className="mt-2 text-[13px] leading-snug text-muted">El POS asigna el correlativo local (SQL 09) y puedes escribir el número de factura y de control a mano. Útil mientras el SENIAT aprueba tu imprenta digital o tu máquina fiscal.</p>}
                            </div>

                            {c.modo === 'MAQUINA_FISCAL' && (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="N.º de registro de la máquina fiscal" value={c.maquina_registro} onChange={(e) => set('maquina_registro', e.target.value)} hint="El número con que el SENIAT registró el equipo." />
                                        <Field label="Punto de emisión" value={c.punto_emision} onChange={(e) => set('punto_emision', e.target.value)} hint="Ej.: 01" />
                                    </div>
                                    <div>
                                        <p className="mb-2 text-[13.5px] font-medium text-muted">Cómo se conecta el sistema con la máquina</p>
                                        <Segmented value={c.maquina_protocolo} onChange={(v) => set('maquina_protocolo', v)} options={PROTOCOLOS} name="Protocolo de máquina fiscal" />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Dirección (IP o host)" value={c.maquina_ip} onChange={(e) => set('maquina_ip', e.target.value)} placeholder="192.168.1.50" hint="La IP de la impresora o del driver del proveedor." />
                                        <Field label="Puerto" type="number" min="1" max="65535" value={c.maquina_puerto} onChange={(e) => set('maquina_puerto', e.target.value)} />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Serie" value={c.serie} onChange={(e) => set('serie', e.target.value)} />
                                        <Field label="Oficina" value={c.oficina} onChange={(e) => set('oficina', e.target.value)} hint="Para el número de control clásico." />
                                    </div>
                                    <Aviso tono="info" icon={Printer}>
                                        La máquina imprime y reporta el número de factura y de control (formato <span className="num">NNNNNNNN-NNNNNNNN-0000000000000000</span>); el sistema los registra en la venta o nota con la fuente «MAQUINA_FISCAL». Las notas de crédito y débito también se imprimen; anular una venta ya facturada emite primero su nota de crédito.
                                    </Aviso>
                                </>
                            )}

                            {c.modo === 'IMPRENTA_DIGITAL' && (
                                <>
                            <div>
                                <p className="mb-2 text-[13.5px] font-medium text-muted">Ambiente</p>
                                <Segmented value={c.ambiente} onChange={(v) => { set('ambiente', v); set('base_url', ''); }} options={AMBIENTES} name="Ambiente de TFHKA" />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Usuario TFHKA" value={c.usuario} onChange={(e) => set('usuario', e.target.value)} autoComplete="off" />
                                <Field label="Clave TFHKA" type="password" value={c.clave} onChange={(e) => set('clave', e.target.value)} autoComplete="new-password" hint={cfg.tiene_clave ? 'Ya hay una clave guardada. Déjala vacía para conservarla.' : 'La clave que te entregó TFHKA.'} />
                            </div>

                            <Field label={`URL del servicio (${c.ambiente === 'DEMO' ? 'demo' : 'producción'})`} value={c.base_url} onChange={(e) => set('base_url', e.target.value)} placeholder={c.ambiente === 'DEMO' ? cfg.demo_url : cfg.produccion_url} hint="Déjala vacía para usar la dirección oficial del ambiente elegido." />

                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Serie" value={c.serie} onChange={(e) => set('serie', e.target.value)} />
                                <Field label="Sucursal" value={c.sucursal} onChange={(e) => set('sucursal', e.target.value)} />
                                <Field label="Código CIIU" value={c.codigo_ciiu} onChange={(e) => set('codigo_ciiu', e.target.value)} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Moneda del documento" select value={c.moneda} onChange={(e) => set('moneda', e.target.value)}>
                                    {MONEDAS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                                </Field>
                                <Field label="Tiempo de espera (segundos)" type="number" min="5" max="300" value={c.timeout_segundos} onChange={(e) => set('timeout_segundos', e.target.value)} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Código PLU por defecto" value={c.codigo_plu_default} onChange={(e) => set('codigo_plu_default', e.target.value)} hint="Se usa si un producto no tiene SKU." />
                                <Field label="Régimen especial de tributación" value={c.regimen_esp_tributacion} onChange={(e) => set('regimen_esp_tributacion', e.target.value)} hint="Solo si el SENIAT te lo otorgó." />
                            </div>

                            <Choice checked={c.auto_emitir} onChange={(v) => set('auto_emitir', v)} title="Emitir automáticamente al vender">
                                Si lo apagas, la factura queda pendiente y la emites desde esta pantalla o desde Ventas.
                            </Choice>
                                </>
                            )}

                            {error && <Aviso tono="bad" icon={TriangleAlert}>{error}</Aviso>}

                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={env || !cfg.tabla_lista}>{env ? 'Guardando…' : 'Guardar cambios'}</Button>
                                <Button type="button" variant="ghost" onClick={probar} disabled={probando || env || !cfg.tabla_lista}>
                                    <RefreshCw className={`size-4 ${probando ? 'animate-spin' : ''}`} />{probando ? 'Probando…' : 'Probar conexión'}
                                </Button>
                            </div>
                        </form>
                    </Tarjeta>

                    <div className="space-y-5">
                        <Tarjeta icon={ShieldCheck} titulo="Cómo funciona" texto="Lo que debes saber antes de emitir documentos reales.">
                            <ul className="space-y-3 text-[14px] leading-snug text-muted">
                                <li className="flex gap-2.5"><CheckCircle2 className="mt-0.5 size-[18px] shrink-0 text-ok" /><span><strong className="text-text">Dos modos como exige el SENIAT:</strong> imprenta digital (la API de TFHKA asigna el número de control, consecutivo y único) o máquina fiscal autorizada (el equipo imprime y reporta su registro y el control). Elige el modo arriba.</span></li>
                                <li className="flex gap-2.5"><CheckCircle2 className="mt-0.5 size-[18px] shrink-0 text-ok" /><span>La <strong className="text-text">factura</strong> usa el número interno de la venta y TFHKA le asigna el número de control y la URL oficial de consulta (QR).</span></li>
                                <li className="flex gap-2.5"><CheckCircle2 className="mt-0.5 size-[18px] shrink-0 text-ok" /><span>Las <strong className="text-text">notas de crédito y débito</strong> llevan numeración fiscal propia (solo dígitos) y quedan vinculadas a la factura afectada.</span></li>
                                <li className="flex gap-2.5"><TriangleAlert className="mt-0.5 size-[18px] shrink-0 text-signal" /><span>Una factura <strong className="text-text">no se anula</strong> en TFHKA: se revierte con una nota de crédito. Anular una venta ya facturada emite esa nota primero.</span></li>
                                <li className="flex gap-2.5"><Download className="mt-0.5 size-[18px] shrink-0 text-brand" /><span>El PDF oficial se guarda y se puede reimprimir sin volver a llamar a la API.</span></li>
                            </ul>
                            <Aviso tono="info" icon={ShieldCheck}>
                                Para pasar a producción solo cambia el ambiente y las credenciales aquí. El sistema no necesita cambios de código.
                            </Aviso>
                        </Tarjeta>

                        <Tarjeta icon={CloudCog} titulo="Formas de pago" texto="Código que TFHKA exige para cada método del negocio. Si lo dejas automático, se deduce del nombre y la moneda.">
                            <div className="space-y-2">
                                {metodosV.map((m) => (
                                    <div key={m.id} className="flex items-center gap-3 rounded-xl border border-line px-3 py-2.5">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-[14px] font-medium">{m.nombre}</p>
                                            <p className="text-[12.5px] text-muted">{m.moneda}{!m.activo ? ' · inactivo' : ''}</p>
                                        </div>
                                        <select value={m.tfhka_forma || ''} onChange={(e) => accion(`m-${m.id}`, 'PUT', `/facturacion-digital/metodos/${m.id}`, { tfhka_forma: e.target.value })} disabled={ocupado === `m-${m.id}`}
                                            className="h-10 rounded-lg border border-line bg-white px-2 text-[13.5px] focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand">
                                            <option value="">Automático</option>
                                            <option value="01">01 · Efectivo</option>
                                            <option value="02">02 · Pago móvil</option>
                                            <option value="03">03 · Transferencia</option>
                                            <option value="04">04 · Tarjeta crédito</option>
                                            <option value="05">05 · Tarjeta débito</option>
                                            <option value="06">06 · Cheque</option>
                                            <option value="07">07 · Otros / crédito</option>
                                            <option value="09">09 · Efectivo divisa</option>
                                        </select>
                                    </div>
                                ))}
                                {!metodosV.length && <p className="text-[13.5px] text-muted">No hay métodos de pago registrados.</p>}
                            </div>
                        </Tarjeta>
                    </div>
                </div>

                <Tarjeta icon={Send} titulo="Ventas por emitir" texto="Ventas completadas cuya factura fiscal aún no está confirmada. Puedes emitir o reintentar aquí mismo.">
                    {pendientesV.length ? (
                        <div className="-mx-6 overflow-x-auto">
                            <table className="w-full min-w-[720px] text-[14px]">
                                <thead><tr className="border-b border-line text-left text-[12.5px] uppercase tracking-wide text-muted">
                                    <th className="px-6 py-2 font-medium">Venta</th><th className="px-3 py-2 font-medium">Fecha</th><th className="px-3 py-2 font-medium">Cliente</th>
                                    <th className="px-3 py-2 text-right font-medium">Total Bs</th><th className="px-3 py-2 font-medium">Estado</th><th className="px-6 py-2 text-right font-medium">Acciones</th>
                                </tr></thead>
                                <tbody>
                                    {pendientesV.map((v) => (
                                        <tr key={v.id} className="border-b border-line/70 last:border-0">
                                            <td className="px-6 py-2.5 font-medium">#{v.numero}</td>
                                            <td className="px-3 py-2.5 text-muted">{v.fecha}</td>
                                            <td className="px-3 py-2.5">{v.cliente}</td>
                                            <td className="num px-3 py-2.5 text-right">{n2(v.total_ves)}</td>
                                            <td className="px-3 py-2.5"><Etiqueta estado={v.estado} /></td>
                                            <td className="px-6 py-2.5">
                                                <div className="flex justify-end gap-2">
                                                    {v.factura && (
                                                        <a href={`/facturacion-digital/ventas/${v.id}/pdf`} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-[13px] font-medium hover:bg-paper"><Download className="size-4" />PDF</a>
                                                    )}
                                                    <Button className="h-9 px-3 text-[13px]" onClick={() => accion(`v-${v.id}`, 'POST', `/facturacion-digital/ventas/${v.id}/emitir`)} disabled={ocupado === `v-${v.id}` || !cfg.activo}>
                                                        <Send className="size-4" />{ocupado === `v-${v.id}` ? 'Emitiendo…' : 'Emitir'}
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <p className="text-[14px] text-muted">No hay ventas pendientes. Todo lo completado tiene su factura fiscal.</p>}
                </Tarjeta>

                <Tarjeta icon={FileText} titulo="Notas por confirmar" texto="Notas de crédito o débito registradas que TFHKA todavía no confirmó. Una nota anulada en el sistema también se puede anular aquí.">
                    {pendientesN.length ? (
                        <div className="-mx-6 overflow-x-auto">
                            <table className="w-full min-w-[720px] text-[14px]">
                                <thead><tr className="border-b border-line text-left text-[12.5px] uppercase tracking-wide text-muted">
                                    <th className="px-6 py-2 font-medium">Nota</th><th className="px-3 py-2 font-medium">Venta</th><th className="px-3 py-2 font-medium">Fecha</th>
                                    <th className="px-3 py-2 text-right font-medium">Total Bs</th><th className="px-3 py-2 font-medium">Estado</th><th className="px-6 py-2 text-right font-medium">Acciones</th>
                                </tr></thead>
                                <tbody>
                                    {pendientesN.map((n) => (
                                        <tr key={n.id} className="border-b border-line/70 last:border-0">
                                            <td className="px-6 py-2.5 font-medium">{n.tipo === 'C' ? 'Crédito' : 'Débito'} {n.numero}</td>
                                            <td className="px-3 py-2.5 text-muted">#{n.venta_numero}</td>
                                            <td className="px-3 py-2.5 text-muted">{n.fecha}</td>
                                            <td className="num px-3 py-2.5 text-right">{n2(n.total_ves)}</td>
                                            <td className="px-3 py-2.5"><Etiqueta estado={n.estado} /></td>
                                            <td className="px-6 py-2.5">
                                                <div className="flex justify-end gap-2">
                                                    <Button className="h-9 px-3 text-[13px]" onClick={() => accion(`n-${n.id}`, 'POST', `/facturacion-digital/notas/${n.id}/emitir`)} disabled={ocupado === `n-${n.id}` || !cfg.activo}>
                                                        <Send className="size-4" />{ocupado === `n-${n.id}` ? 'Emitiendo…' : 'Emitir'}
                                                    </Button>
                                                    <Button variant="ghost" className="h-9 px-3 text-[13px]" onClick={() => anularNota(n.id)} disabled={ocupado === `anular-${n.id}` || !n.numero_fiscal}>
                                                        Anular
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <p className="text-[14px] text-muted">No hay notas pendientes.</p>}
                </Tarjeta>

                <Tarjeta icon={RefreshCw} titulo="Últimos llamados a TFHKA" texto="Bitácora de emisión, PDF, estado y anulación. Sirve para saber qué se envió y qué respondió la API.">
                    {eventosV.length ? (
                        <ul className="divide-y divide-line/70">
                            {eventosV.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-start gap-x-4 gap-y-1 py-2.5 text-[13.5px]">
                                    <span className="w-[120px] shrink-0 text-muted">{e.fecha}</span>
                                    <span className="w-[92px] shrink-0 font-medium">{e.operacion}</span>
                                    <Etiqueta estado={e.estado} />
                                    <span className="min-w-0 flex-1 text-muted">{e.mensaje || '—'}{e.numero_documento ? ` · doc ${e.numero_documento}` : ''}</span>
                                </li>
                            ))}
                        </ul>
                    ) : <p className="text-[14px] text-muted">Todavía no hay llamados registrados.</p>}
                </Tarjeta>
            </div>
        </AppLayout>
    );
}

/* Lucide no exporta un reloj con el nombre que usa el Kpi; se declara aquí para no cargar otro icono. */
function Clock(props) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden {...props}>
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
        </svg>
    );
}