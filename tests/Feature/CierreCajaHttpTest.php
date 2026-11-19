<?php

/*
 * CierreCajaHttpTest · el cierre del turno (Corte Z) POR HTTP, con la pila completa.
 *
 * Cubre las reglas del módulo multimoneda que viven en la capa HTTP:
 *   · la NOTA DEL CIERRE es obligatoria si CUALQUIER moneda descuadra (422 sin ella);
 *   · el cajero solo cierra SU caja (403 con la de otro);
 *   · el DOBLE CIERRE del mismo turno se rechaza;
 *   · el snapshot queda inmutable en cierres_caja con conteo/esperado/diferencia por moneda,
 *     desglose bancario (banco) y tasas congeladas (tasas);
 *   · /caja-en-vivo es del administrador y /caja/cerrar devuelve al POS si no hay turno abierto.
 *
 * Cada prueba abre su propio turno multimoneda (fondo USD + Bs con equivalencias) y lo
 * deja cerrado al terminar para no contaminar la base de la suite.
 */

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** Cierra cualquier turno rezagado del usuario (auto-limpieza: un fallo previo no contamina la siguiente prueba). */
function cerrarCajaSiAbierta(int $uid): void
{
    $ap = DB::scalar('SELECT fn_apertura_activa(?)', [$uid]);
    if ($ap) {
        DB::statement('SELECT fn_cerrar_apertura(?, ?)', [$ap, $uid]);
    }
}

function abrirTurnoPrueba(int $uid): int
{
    cerrarCajaSiAbierta($uid);
    return (int) DB::scalar('SELECT fn_abrir_caja(?, 80, 73331.82, 0, 0, ?)', [$uid, 'USD']);
}

function cierreDePrueba(int $uid, array $conteo, ?string $nota, ?float $faltanUsd = null): array
{
    $ap = abrirTurnoPrueba($uid);
    $r = json_decode((string) DB::scalar('SELECT fn_resumen_apertura(?)::text', [$ap]), true);
    $esperado = [];
    foreach ($r['monedas'] as $m) {
        $esperado[$m['moneda']] = (float) $m['esperado'];
    }
    $conteoFinal = $conteo ?: array_map(fn ($v) => $v, $esperado);
    if ($faltanUsd !== null) {
        $conteoFinal['USD'] = round($esperado['USD'] - $faltanUsd, 2);
    }
    return [$ap, $esperado, $conteoFinal];
}

it('rechaza el cierre sin nota cuando una moneda descuadra (la nota es obligatoria)', function () {
    $uid = cajeroActivoId();
    [$ap, $esperado, $conteo] = cierreDePrueba($uid, [], null, faltanUsd: 5.0);

    $resp = $this->actingAs(Usuario::find($uid))
        ->postJson('/cierre-caja', ['apertura_id' => $ap, 'conteo' => $conteo, 'observaciones' => '']);

    $resp->assertStatus(422);
    expect($resp->json('errors.observaciones.0'))->toContain('nota del cierre');

    // NADA cambió: el turno sigue abierto y sin snapshot.
    expect(DB::table('aperturas_caja')->where('id', $ap)->value('estado'))->toBe('ABIERTA');
    expect(DB::table('cierres_caja')->where('apertura_id', $ap)->count())->toBe(0);

    DB::statement('SELECT fn_cerrar_apertura(?, ?)', [$ap, $uid]); // dejar limpio
});

it('acepta el cierre con nota y firma el snapshot con tasas congeladas y desglose bancario', function () {
    $uid = cajeroActivoId();
    $punto = DB::table('metodos_pago')->where('nombre', 'Punto de venta')->value('id');
    $prod = productoActivo();
    asegurarStock($prod, 3);

    $ap = abrirTurnoPrueba($uid);
    // una venta por el banco (punto de venta en Bs): entra al snapshot «banco», no a la gaveta
    $cot = DB::selectOne('SELECT (fn_cotizar_venta(?)).total_usd AS total, (fn_cotizar_venta(?)).tasa_cambio AS tasa', [json_encode([['producto_id' => $prod->id, 'cantidad' => 1, 'descuento_pct' => 0]]), json_encode([['producto_id' => $prod->id, 'cantidad' => 1, 'descuento_pct' => 0]])]);
    $this->actingAs(Usuario::find($uid))
        ->postJson('/caja/venta', ['items' => [['producto_id' => $prod->id, 'cantidad' => 1, 'descuento_pct' => 0]],
            'pagos' => [['metodo_pago_id' => $punto, 'monto' => round($cot->total * $cot->tasa, 2), 'referencia' => 'POS-PRUEBA-1']],
            'tipo_emision' => 'PRESENCIAL'])->assertSuccessful();

    $resumen = json_decode((string) DB::scalar('SELECT fn_resumen_apertura(?)::text', [$ap]), true);
    $conteo = [];
    foreach ($resumen['monedas'] as $m) {
        $conteo[$m['moneda']] = (float) $m['esperado']; // cuadra exacto
    }

    $resp = $this->actingAs(Usuario::find($uid))
        ->postJson('/cierre-caja', ['apertura_id' => $ap, 'conteo' => $conteo, 'observaciones' => 'Cierre de prueba cuadrado']);

    $resp->assertStatus(200);
    $row = DB::table('cierres_caja')->where('apertura_id', $ap)->first();
    expect($row)->not->toBeNull();
    expect(json_decode($row->tasas, true))->toHaveKey('USD')->toHaveKey('EUR')->toHaveKey('COP'); // tasas congeladas
    expect(json_decode($row->banco, true)[0]['metodo'])->toBe('Punto de venta');                 // desglose bancario
    expect(json_decode($row->diferencia, true)['USD'])->toBe('0.00');                            // bcmath exacto
    expect(DB::table('aperturas_caja')->where('id', $ap)->value('estado'))->toBe('CERRADA');
});

it('el cajero NO puede cerrar la caja de otro cajero', function () {
    $cajero = cajeroActivoId();
    $otro = (int) (DB::table('usuarios')->where('rol', 'CAJERO')->where('id', '!=', $cajero)->value('id')
        ?? DB::table('usuarios')->where('rol', 'ADMINISTRADOR')->where('id', '!=', $cajero)->value('id'));
    $apDeOtro = abrirTurnoPrueba($otro);

    $r = json_decode((string) DB::scalar('SELECT fn_resumen_apertura(?)::text', [$apDeOtro]), true);
    $conteo = [];
    foreach ($r['monedas'] as $m) {
        $conteo[$m['moneda']] = (float) $m['esperado'];
    }

    $resp = $this->actingAs(Usuario::find($cajero))
        ->postJson('/cierre-caja', ['apertura_id' => $apDeOtro, 'conteo' => $conteo, 'observaciones' => 'intento ajeno']);

    expect($resp->status())->toBe(403);
    expect(DB::table('aperturas_caja')->where('id', $apDeOtro)->value('estado'))->toBe('ABIERTA');
    DB::statement('SELECT fn_cerrar_apertura(?, ?)', [$apDeOtro, $otro]);
});

it('rechaza el doble cierre del mismo turno', function () {
    $uid = cajeroActivoId();
    $ap = abrirTurnoPrueba($uid);
    $r = json_decode((string) DB::scalar('SELECT fn_resumen_apertura(?)::text', [$ap]), true);
    $conteo = [];
    foreach ($r['monedas'] as $m) {
        $conteo[$m['moneda']] = (float) $m['esperado'];
    }

    $this->actingAs(Usuario::find($uid))
        ->postJson('/cierre-caja', ['apertura_id' => $ap, 'conteo' => $conteo, 'observaciones' => 'primera vez'])
        ->assertStatus(200);

    $resp = $this->actingAs(Usuario::find($uid))
        ->postJson('/cierre-caja', ['apertura_id' => $ap, 'conteo' => $conteo]);

    expect($resp->status())->toBe(422);
    expect($resp->json('errors.apertura_id.0'))->toContain('ya está cerrado');
});

it('Caja en vivo es del administrador y Cerrar caja devuelve al POS sin turno abierto', function () {
    // cajero → /caja-en-vivo bloqueado por rol
    $resp = $this->actingAs(Usuario::find(cajeroActivoId()))->get('/caja-en-vivo');
    expect($resp->status())->toBe(302);
    expect($resp->headers->get('Location'))->toContain('/caja');

    // admin sin caja abierta → /caja/cerrar regresa al POS (donde abre la suya)
    DB::statement('SELECT fn_cerrar_apertura(?, ?) WHERE fn_apertura_activa(?) IS NOT NULL', [fn_apertura_activa_hoy(), adminId(), adminId()]);
    $resp2 = $this->actingAs(Usuario::find(adminId()))->get('/caja/cerrar');
    expect($resp2->status())->toBe(302);

    // admin → /caja-en-vivo responde
    $resp3 = $this->actingAs(Usuario::find(adminId()))->get('/caja-en-vivo');
    expect(in_array($resp3->status(), [200, 302]))->toBeTrue();

    // admin CON caja abierta → /caja/cerrar y /caja-en-vivo renderizan (200, no 500)
    $ap = (int) DB::scalar('SELECT fn_abrir_caja(?, 50, 0, 0, 0, ?)', [adminId(), 'USD']);
    $resp4 = $this->actingAs(Usuario::find(adminId()))->get('/caja/cerrar');
    expect($resp4->status())->toBe(200);
    $resp5 = $this->actingAs(Usuario::find(adminId()))->get('/caja-en-vivo');
    expect($resp5->status())->toBe(200);
    DB::statement('SELECT fn_cerrar_apertura(?, ?)', [$ap, adminId()]);
});

/** Cierra cualquier turno abierto del usuario dado (limpieza entre pruebas). */
function fn_apertura_activa_hoy(): int
{
    return (int) (DB::scalar('SELECT fn_apertura_activa(?)', [adminId()]) ?? 0);
}
