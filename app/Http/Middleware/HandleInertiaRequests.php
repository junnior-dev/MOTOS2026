<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** Lo que todas las páginas reciben: el aviso breve tras guardar (flash.ok) y la tasa del día para el encabezado. */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => ['ok' => fn () => $request->session()->get('ok')],
            'tasa_usd' => fn () => $this->tasaUsd(),
        ];
    }

    /** Tasa oficial vigente del dólar para el badge del encabezado en todas las pantallas. */
    private function tasaUsd(): ?float
    {
        try {
            $t = \Illuminate\Support\Facades\DB::table('v_tasa_actual')->value('tasa');
            return $t ? (float) $t : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
