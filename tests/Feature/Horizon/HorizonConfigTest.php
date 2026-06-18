<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Horizon\HorizonServiceProvider;

it('конфиг horizon существует и содержит окружения production и local', function () {
    $config = config('horizon');
    expect($config)->toBeArray();
    expect($config)->toHaveKey('environments');
    expect($config['environments'])->toHaveKey('production');
    expect($config['environments'])->toHaveKey('local');
});

it('окружение local содержит supervisor-default и supervisor-collect', function () {
    $local = config('horizon.environments.local');
    expect($local)->toHaveKey('supervisor-default');
    expect($local)->toHaveKey('supervisor-collect');
});

it('supervisor-collect имеет queue collect и timeout 590', function () {
    $supervisor = config('horizon.environments.local.supervisor-collect');
    expect($supervisor['queue'])->toBe(['collect']);
    expect($supervisor['timeout'])->toBe(590);
});

it('HorizonServiceProvider зарегистрирован в приложении', function () {
    expect(app()->providerIsLoaded(HorizonServiceProvider::class))->toBeTrue();
});

it('суперадмин имеет доступ к /horizon', function () {
    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get('/horizon')
        ->assertStatus(200);
});

it('обычный admin не имеет доступа к /horizon', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get('/horizon')
        ->assertStatus(403);
});
