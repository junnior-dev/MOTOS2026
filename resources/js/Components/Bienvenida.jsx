// Pantalla de bienvenida para un usuario que aún no vio la guía: cubre TODO
// (el panel queda oculto detrás) y solo ofrece iniciar el tour o saltarlo.
// Ambos caminos marcan la guía como vista en la base de datos.
import { Sparkles, Wrench } from 'lucide-react';
import { pasosPorRol } from '../lib/tour';

export default function Bienvenida({ nombre, rol, onIniciar, onSaltar }) {
    const primero = (nombre || '').split(' ')[0] || 'bienvenido';
    const pasos = pasosPorRol(rol);
    return (
        <div className="fixed inset-0 z-[70] flex flex-col items-center justify-center bg-paper px-6 text-center">
            <span className="grid size-24 place-items-center rounded-3xl bg-white shadow-sm ring-1 ring-line">
                <Wrench className="size-12 text-brand" aria-hidden />
            </span>
            <h1 className="mt-8 text-4xl font-semibold tracking-[-0.02em] sm:text-5xl">¡Bienvenido, {primero}!</h1>
            <p className="mt-4 max-w-md text-[15.5px] leading-relaxed text-muted">
                Esta es tu primera vez en SmartMotos. En {pasos} pasos cortos te mostramos las
                pantallas que vas a usar todos los días{pasos === 6 ? ', del panel al control de usuarios' : ', del buscador al cobro'}.
            </p>
            <button onClick={onIniciar} className="mt-9 inline-flex h-12 items-center gap-2.5 rounded-xl bg-brand px-7 text-[15px] font-semibold text-white hover:bg-brand-600">
                <Sparkles className="size-5" aria-hidden /> Iniciar tour · Conoce SmartMotos
            </button>
            <button onClick={onSaltar} className="mt-4 text-[13.5px] font-medium text-muted hover:text-text hover:underline">
                Saltar por ahora
            </button>
            <p className="mt-10 text-[12.5px] text-muted">Podrás ver la guía de nuevo desde el menú, cuando quieras.</p>
        </div>
    );
}
