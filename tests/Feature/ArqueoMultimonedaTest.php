<?php

/*
 * ArqueoMultimonedaTest · reglas del arqueo multimoneda del cierre de caja
 * (App\Services\Caja\CashRegisterService sobre Money + decimales de la BD).
 * Resumen de prueba con la MISMA forma que devuelve fn_resumen_apertura:
 * USD y VES con actividad; EUR y COP según actividad del turno.
 */

use App\Services\Caja\CashRegisterService;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** Resumen con la misma forma que devuelve fn_resumen_apertura (4 monedas).
 * @return array{monedas: list<array{moneda: string, esperado: string, efectivo_recibido: string}>} */
function resumenArqueo(): array
{
    return ['monedas' => [
        ['moneda' => 'USD', 'esperado' => '100.00', 'efectivo_recibido' => '50.00'],
        ['moneda' => 'VES', 'esperado' => '500.00', 'efectivo_recibido' => '0'],
        ['moneda' => 'EUR', 'esperado' => '0', 'efectivo_recibido' => '0'],
        ['moneda' => 'COP', 'esperado' => '50000', 'efectivo_recibido' => '0'],
    ]];
}

it('exige contar USD y VES (siempre) y COP (con actividad), pero no EUR sin actividad', function () {
    $svc = app(CashRegisterService::class);
    $conteo = ['USD' => '150', 'VES' => '500.00', 'COP' => '50000'];
    [$esperado, $conteoCuadrado, $dif] = $svc->cuadrarEfectivo(resumenArqueo(), $conteo, $svc->decimalesPorMoneda());

    // EUR inactivo: sin clave en el conteo ni en las diferencias
    expect(array_keys($esperado))->toContain('USD', 'VES', 'COP')
        ->and(array_keys($esperado))->not->toContain('EUR')
        ->and($conteoCuadrado['USD'])->toBe('150.00')
        ->and($dif['USD'])->toBe('50.00'); // sobró 50 USD en la gaveta
});

it('falla el arqueo si falta el conteo de una moneda activa (puede ser 0)', function () {
    $svc = app(CashRegisterService::class);
    $conteo = ['VES' => '500.00', 'COP' => '0'];

    expect(fn () => $svc->cuadrarEfectivo(resumenArqueo(), $conteo, $svc->decimalesPorMoneda()))
        ->toThrow(ValidationException::class);
});

it('falla con mensaje claro si el conteo es negativo', function () {
    $svc = app(CashRegisterService::class);
    $conteo = ['USD' => '-1', 'VES' => '500.00', 'COP' => '0'];

    try {
        $svc->cuadrarEfectivo(resumenArqueo(), $conteo, $svc->decimalesPorMoneda());
        $this->fail('Se esperaba ValidationException por conteo negativo.');
    } catch (ValidationException $e) {
        expect($e->errors()['conteo'][0])->toContain('USD');
    }
});

it('respeta la precisión de cada moneda: COP sin centavos, USD con centavos', function () {
    $svc = app(CashRegisterService::class);
    $conteo = ['USD' => '150.00', 'VES' => '500.004', 'COP' => '50000.7'];
    [, $conteoCuadrado] = $svc->cuadrarEfectivo(resumenArqueo(), $conteo, $svc->decimalesPorMoneda());

    expect($conteoCuadrado['USD'])->toBe('150.00')
        ->and($conteoCuadrado['VES'])->toBe('500.00')
        ->and($conteoCuadrado['COP'])->toBe('50000');
});

it('detecta el descuadre y exige nota de cierre explicándolo por moneda', function () {
    $svc = app(CashRegisterService::class);
    $conteo = ['USD' => '150.00', 'VES' => '498.50', 'COP' => '50000'];
    [, , $dif] = $svc->cuadrarEfectivo(resumenArqueo(), $conteo, $svc->decimalesPorMoneda());

    expect($svc->hayDescuadre($dif))->toBeTrue();

    try {
        $svc->exigirNotaSiDescuadra($dif, '');
        $this->fail('Se esperaba ValidationException por nota obligatoria.');
    } catch (ValidationException $e) {
        expect($e->errors()['observaciones'][0])->toContain('USD')
            ->and($e->errors()['observaciones'][0])->toContain('VES');
    }

    // Con nota no explota y devuelve sin excepción
    $svc->exigirNotaSiDescuadra($dif, 'Faltan 1.50 USD: vuelto entregado sin registrar en gaveta VES. Revisado con el cajero.');
    expect(true)->toBeTrue();
});

it('una diferencia menor al medio centavo cuenta como caja cuadrada', function () {
    $dif = ['USD' => '-0.004', 'VES' => '0.00'];
    expect((new CashRegisterService)->hayDescuadre($dif))->toBeFalse()
        ->and(Money::esCero($dif['USD']))->toBeTrue();
});

it('caja cuadrada exacta no exige nota', function () {
    $svc = app(CashRegisterService::class);
    $conteo = ['USD' => '100.00', 'VES' => '500.00', 'COP' => '50000'];
    [, , $dif] = $svc->cuadrarEfectivo(resumenArqueo(), $conteo, $svc->decimalesPorMoneda());

    expect($svc->hayDescuadre($dif))->toBeFalse();
    $svc->exigirNotaSiDescuadra($dif, null);
    expect(true)->toBeTrue();
});
