<?php

/*
 * MoneyTest · contrato de aritmética exacta de dinero (app/Support/Money.php).
 * Puro, sin base de datos: lo que la caja y el cierre usarán en PHP.
 * Nota QA: redondear() TRUNCA a la precisión de la moneda (bcadd), no redondea
 * medio punto hacia arriba; es el comportamiento documentado del snapshot del
 * cierre (los cálculos contables exactos viven en SQL NUMERIC).
 */

use App\Support\Money;

it('redondea (trunca) a 2 decimales la precisión por defecto', function () {
    expect(Money::redondear('122.7749'))->toBe('122.77')
        ->and(Money::redondear('120.00'))->toBe('120.00')
        ->and(Money::redondear('99.999'))->toBe('99.99');
});

it('trunca a 0 decimales la moneda sin centavos (COP)', function () {
    expect(Money::redondear('36.5', 0))->toBe('36')
        ->and(Money::redondear('50000.7', 0))->toBe('50000');
});

it('resta sin errores de coma flotante, con signo', function () {
    expect(Money::restar('120.00', '121.50'))->toBe('-1.50')
        ->and(Money::restar('121.50', '120.00'))->toBe('1.50')
        ->and(Money::restar('0.10', '0.02'))->toBe('0.08');
});

it('suma y compara exactamente', function () {
    expect(Money::sumar('0.10', '0.20'))->toBe('0.30')
        ->and(Money::comparar('0.30', '0.30'))->toBe(0)
        ->and(Money::comparar('0.29', '0.30'))->toBe(-1)
        ->and(Money::comparar('0.31', '0.30'))->toBe(1);
});

it('normaliza enteros y flotantes a string decimal, sin notación científica', function () {
    expect(Money::normalizar(120))->toBe('120.000000')
        ->and(Money::normalizar(120.5))->toBe('120.500000')
        ->and(Money::normalizar(1.0E-7))->toBe('0.000000');
});

it('tolera el medio centavo en la cuadratura (esCero)', function () {
    expect(Money::esCero('0.0000'))->toBeTrue()
        ->and(Money::esCero('-0.004'))->toBeTrue()
        ->and(Money::esCero('0.004'))->toBeTrue()
        ->and(Money::esCero('0.005'))->toBeFalse()
        ->and(Money::esCero('1.50'))->toBeFalse();
});
