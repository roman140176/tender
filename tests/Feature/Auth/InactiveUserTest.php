<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('inactive user can not authenticate', function () {
    $user = User::factory()->create(['is_active' => false]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
    $response->assertRedirect();
});

test('active user can authenticate', function () {
    $user = User::factory()->create(['is_active' => true]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('user deactivated mid-session is logged out on next request', function () {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user);

    $user->update(['is_active' => false]);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('gate denies every ability for inactive user', function () {
    $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);

    expect(Gate::forUser($user)->allows('manage-company'))->toBeFalse();
});

test('gate allows ability for active user', function () {
    $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

    expect(Gate::forUser($user)->allows('manage-company'))->toBeTrue();
});
