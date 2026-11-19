<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CerrarCajaRequest · validación de entrada del cierre del turno (Corte Z).
 *
 * Las reglas contextuales viven en CashRegisterService: qué monedas hay que contar
 * (las que tuvieron efectivo) y la nota obligatoria cuando cualquier moneda descuadra.
 * Aquí se valida la forma: conteo por moneda, sin negativos, nota acotada.
 */
class CerrarCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol lo aplica AccesoPorRol y la propiedad de la caja lo verifica
        // CierreCajaController (un cajero solo cierra su propia caja).
        return true;
    }

    public function rules(): array
    {
        return [
            'apertura_id' => 'required|integer',
            'conteo' => 'required|array',
            'conteo.*' => 'nullable|numeric|min:0|max:999999999999',
            'observaciones' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'conteo.required' => 'Cuenta el efectivo de la gaveta antes de cerrar.',
            'conteo.*.min' => 'El conteo no puede ser negativo.',
            'observaciones.max' => 'La nota del cierre no puede pasar de 500 caracteres.',
        ];
    }
}
