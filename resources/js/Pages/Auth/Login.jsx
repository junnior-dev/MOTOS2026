import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Boxes, Coins, Lock, ReceiptText, ShieldCheck, User } from 'lucide-react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Button, Field, PasswordField } from '../../Components/ui';

/* Columna informativa del login: solo iconos y texto, sin mockups ni animaciones. */
const PUNTOS = [
    [ReceiptText, 'Facturación clara, sin calcular', 'El IVA, el IGTF y la tasa del día se aplican solos. La factura no cambia aunque el BCV mueva la tasa mañana.'],
    [Boxes, 'Inventario al día', 'Cada repuesto vendido descuenta el stock en el momento. Ves lo que falta antes de que el cliente lo pida.'],
    [Coins, 'Cobros en varias monedas', 'Bolívares, dólares, euros y pesos en una misma factura, con el vuelto y la tasa bien calculados.'],
    [ShieldCheck, 'Cada quien ve lo suyo', 'El cajero cobra y cierra su turno; tú revisas costos, compras y reportes. Todo queda registrado.'],
];

const Aside = () => (
    <>
        <h2 className="max-w-[22ch] text-[32px] font-semibold leading-[1.12] tracking-[-0.02em]">
            Menos cuentas a mano. Más tiempo en el mostrador.
        </h2>
        <p className="mt-4 max-w-[46ch] text-[15px] leading-relaxed text-white/65">
            Ventas, inventario y cobros en un solo lugar. Pensado para el día a día de una tienda de repuestos: rápido cuando hay fila y ordenado al cerrar la caja.
        </p>
        <ul className="mt-7 space-y-5">
            {PUNTOS.map(([Icon, titulo, texto]) => (
                <li key={titulo} className="flex gap-4">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl border border-white/10 bg-white/[0.06] text-white"><Icon className="size-5" /></span>
                    <span>
                        <span className="block text-[15px] font-medium">{titulo}</span>
                        <span className="mt-0.5 block max-w-[40ch] text-sm leading-relaxed text-white/55">{texto}</span>
                    </span>
                </li>
            ))}
        </ul>
    </>
);

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ username: '', password: '', remember: true });
    const submit = (e) => { e.preventDefault(); post('/login'); };

    return (
        <AuthLayout aside={<Aside />}>
            <Head title="Iniciar sesión" />
            <h1 className="text-[28px] font-semibold tracking-[-0.02em]">Iniciar sesión</h1>
            <p className="mt-2 text-[15px] text-muted">Escribe tu usuario y tu contraseña para abrir la caja o revisar el inventario.</p>

            <form onSubmit={submit} className="mt-8 space-y-5" noValidate>
                <Field label="Usuario" icon={User} value={data.username} onChange={(e) => setData('username', e.target.value)}
                    error={errors.username} autoComplete="username" autoFocus placeholder="Ej: admin" />
                <PasswordField label="Contraseña" icon={Lock} value={data.password} onChange={(e) => setData('password', e.target.value)}
                    error={errors.password} autoComplete="current-password"  />
                <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                    <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} className="size-4 rounded border-line accent-brand" />
                    Recordarme en este equipo
                </label>
                <Button type="submit" disabled={processing} className="w-full">Iniciar sesión <ArrowRight className="size-4" /></Button>
            </form>

            <p className="mt-8 border-t border-line pt-6 text-sm text-muted">
                ¿Es la primera vez que usas el sistema?{' '}
                <Link href="/registro" className="font-semibold text-brand hover:underline">Crea tu cuenta</Link>
            </p>
        </AuthLayout>
    );
}
