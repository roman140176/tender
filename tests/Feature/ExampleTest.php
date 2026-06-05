<?php

use App\Models\User;

it('гость с корня попадает на логин', function () {
    $this->get('/')->assertRedirect(route('login', absolute: false));
});

it('авторизованный с корня попадает на дашборд', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard', absolute: false));
});
