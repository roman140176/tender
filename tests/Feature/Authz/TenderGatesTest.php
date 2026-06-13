<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('view-tenders пускает любого пользователя компании, но не суперадмина без компании', function (UserRole $role, bool $allowed) {
    $user = $role === UserRole::Superadmin
        ? User::factory()->superadmin()->create()
        : User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('view-tenders'))->toBe($allowed);
})->with([
    [UserRole::Admin, true],
    [UserRole::Manager, true],
    [UserRole::Viewer, true],
    [UserRole::Superadmin, false],
]);

it('manage-tender-workflow пускает admin и manager, но не viewer и не суперадмина', function (UserRole $role, bool $allowed) {
    $user = $role === UserRole::Superadmin
        ? User::factory()->superadmin()->create()
        : User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('manage-tender-workflow'))->toBe($allowed);
})->with([
    [UserRole::Admin, true],
    [UserRole::Manager, true],
    [UserRole::Viewer, false],
    [UserRole::Superadmin, false],
]);
