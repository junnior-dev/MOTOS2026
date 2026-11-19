import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { LockOpen, LockKeyhole, Receipt, Store } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { Card } from '../../Components/kit';
import { Button } from '../../Components/ui';
import { ModalApertura } from './Turno';

/* Abrir caja: la puerta del menú «Caja > Abrir caja». Si ya tienes caja abierta el
   backend te lleva a Caja en vivo; si no, aquí se abre el mismo modal multimoneda del POS. */

export default function AbrirCaja(p) {
    const { usuario, negocio, tasas, estado, fecha_larga, tasa_usd, stock_bajo } = p;
    const [modal, setModal] = useState(true);
    return (
        <AppLayout actual="/caja/abrir" titulo="Abrir caja" subtitulo="Cuenta el fondo con el que arrancas el turno, en la moneda que prefieras." usuario={usuario} negocio={negocio} tasaUsd={tasa_usd} stockBajo={stock_bajo}>
            <Head title="Abrir caja" />
            <p className="mb-1 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.14em] text-muted"><span className="inline-block h-px w-6 bg-line" aria-hidden />Dinero · Caja</p>
            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <h1 className="text-[26px] font-bold tracking-[-0.02em]">Abrir caja</h1>
            </div>
            <Card className="mx-auto max-w-[640px] p-6">
                <span className="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand"><LockOpen className="size-6" /></span>
                <h2 className="mt-4 text-lg font-semibold tracking-[-0.01em]">Tu caja está cerrada</h2>
                <p className="mt-1.5 text-[14.5px] leading-relaxed text-muted">
                    Para vender en el punto de venta necesitas un turno abierto con su fondo inicial.
                    El fondo puede contarse en dólares, bolívares, euros o pesos colombianos; el sistema
                    guarda el equivalente en las cuatro con las tasas vigentes de hoy y al cerrar la caja
                    se compara contra él, moneda por moneda.
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button onClick={() => setModal(true)} className="h-11 px-5"><LockKeyhole className="size-4" />Abrir caja</Button>
                    <a href="/caja-en-vivo" className="inline-flex h-11 items-center gap-2 rounded-lg border border-line bg-white px-4 text-[14.5px] font-semibold hover:bg-paper"><Store className="size-4" />Ver las cajas abiertas</a>
                </div>
                {estado === 'sin_tasa' && (
                    <p className="mt-4 flex items-start gap-2 text-[13.5px] text-[#8a5d00]"><Receipt className="mt-0.5 size-4 shrink-0" />No hay tasa vigente para hoy: cárgala en <a href="/tasas" className="font-semibold text-brand hover:underline">Tasas de cambio</a> antes de vender.</p>
                )}
            </Card>
            {modal && <ModalApertura usuario={usuario} tasas={tasas} alAbrir={() => router.visit('/caja')} />}
        </AppLayout>
    );
}
