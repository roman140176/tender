<?php

use App\Models\User;

it('регистрация недоступна', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('верификация почты недоступна', function () {
    $this->get('/verify-email')->assertNotFound();
});

it('логин работает и ведёт на дашборд', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
