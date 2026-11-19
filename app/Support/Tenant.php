<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Identidad de la tienda activa para los módulos con mentalidad SaaS
 * (facturación digital TFHKA). En modo tienda única siempre responde el
 * negocio 1 (la única fila de negocio); la clase existe para que esos
 * módulos ya queden alineados con el modelo multiinquilino del portal
 * y no haya que tocarlos cuando llegue el portal.
 */
class Tenant
{
    /** Identificador del negocio activo (null si la base aún no está instalada). */
    public static function id(): ?int
    {
        try {
            return (int) DB::scalar('SELECT id FROM negocio ORDER BY id LIMIT 1') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** ¿El plan de la tienda incluye el módulo fiscal? Tienda única: siempre sí. */
    public static function fiscal(): bool
    {
        return true;
    }
}
