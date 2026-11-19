<?php

/*
 * TourBienvenidaTest · la guía de bienvenida «Conoce SmartMotos»: el contexto
 * de página comparte tour_completado con el front, un usuario nuevo la trae
 * en FALSE y el POST /tour/completar la marca como vista (valga terminarla o
 * cerrarla). Sin auth, el endpoint no es accesible.
 */

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(\App\Models\Usuario::find(adminId()));
});

it('el contexto de página comparte tour_completado con el layout', function () {
    DB::table('usuarios')->where('id', adminId())->update(['tour_completado' => false]);
    // actingAs fija el modelo en memoria: recargarlo tras el UPDATE simula el
    // request real, donde cada petición lee al usuario fresco de la base.
    $this->actingAs(\App\Models\Usuario::find(adminId()));
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('usuario.tour_completado', false));
});

it('un usuario recién creado trae la guía pendiente (false por defecto)', function () {
    $nuevo = DB::table('usuarios')->where('username', 'tour_nuevo')->first();
    if (! $nuevo) {
        $id = DB::table('usuarios')->insertGetId([
            'username' => 'tour_nuevo', 'password_hash' => 'PENDIENTE',
            'nombre_completo' => 'Usuario Tour Nuevo', 'rol' => 'CAJERO',
        ]);
        $nuevo = (object) ['id' => $id];
    }
    expect((bool) DB::table('usuarios')->where('id', $nuevo->id)->value('tour_completado'))->toBeFalse();
});

it('al terminar o cerrar el tour queda marcado como visto', function () {
    DB::table('usuarios')->where('id', adminId())->update(['tour_completado' => false]);
    expect((bool) DB::table('usuarios')->where('id', adminId())->value('tour_completado'))->toBeFalse();

    $this->post('/tour/completar')->assertRedirect();
    expect((bool) DB::table('usuarios')->where('id', adminId())->value('tour_completado'))->toBeTrue();

    // Segunda llamada: idempotente, sigue en TRUE sin error.
    $this->post('/tour/completar')->assertRedirect();
    expect((bool) DB::table('usuarios')->where('id', adminId())->value('tour_completado'))->toBeTrue();
});

it('un invitado no puede marcar la guía como vista', function () {
    \Illuminate\Support\Facades\Auth::logout();
    $this->post('/tour/completar')->assertRedirect('/login');
});

it('relanzar la guía no la vuelve a marcar pendiente (el valor solo cambia con la marca explícita)', function () {
    // El relanzado es solo front (driver.js sobre la sesión actual); el campo
    // permanece TRUE: la bienvenida solo se muestra cuando está en FALSE.
    expect((bool) DB::table('usuarios')->where('id', adminId())->value('tour_completado'))->toBeTrue();
});
