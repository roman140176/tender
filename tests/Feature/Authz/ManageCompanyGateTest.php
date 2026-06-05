<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('gate manage-company пускает только админа компании', function (UserRole $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);
    expect(Gate::forUser($user)->allows('manage-company'))->toBe($allowed);
})->with([
    [UserRole::Admin, true],
    [UserRole::Manager, false],
    [UserRole::Viewer, false],
]);

it('суперадмин без компании не управляет кабинетом компании', function () {
    $root = User::factory()->superadmin()->create();
    expect(Gate::forUser($root)->allows('manage-company'))->toBeFalse();
});
