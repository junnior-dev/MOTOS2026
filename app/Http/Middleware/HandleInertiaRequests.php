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
            'rif_negocio' => fn () => $this->rifNegocio(),
        ];
    }

    /** RIF del negocio para la ficha de la barra lateral. Null mientras no esté configurado. */
    private function rifNegocio(): ?string
    {
        try {
            $rif = \Illuminate\Support\Facades\DB::table('negocio')->where('id', 1)->value('rif');
            return $rif ? trim((string) $rif) : null;
        } catch (\Throwable) {
            return null;
        }
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
