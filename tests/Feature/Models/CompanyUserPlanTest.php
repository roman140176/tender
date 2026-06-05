<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;

it('связывает компанию, пользователей и подписку', function () {
    $company = Company::factory()->create(['industries' => ['металлообработка']]);
    $admin = User::factory()->for($company)->create(['role' => UserRole::Admin]);

    $this->seed(PlanSeeder::class);
    $plan = Plan::where('code', 'basic')->firstOrFail();
    $company->subscriptions()->create([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'started_at' => now(),
    ]);

    expect($company->users->first()->is($admin))->toBeTrue()
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($company->industries)->toBe(['металлообработка'])
        ->and($company->activeSubscription()?->plan->code)->toBe('basic')
        ->and(Plan::count())->toBe(3);
});

it('суперадмин не принадлежит компании', function () {
    $root = User::factory()->superadmin()->create();
    expect($root->company_id)->toBeNull()->and($root->role)->toBe(UserRole::Superadmin);
});

it('каскадно удаляет пользователей и подписки вместе с компанией', function () {
    $company = Company::factory()->create();
    User::factory()->for($company)->create();
    Subscription::factory()->for($company)->create();

    $company->delete();

    expect(User::count())->toBe(0)
        ->and(Subscription::count())->toBe(0);
});
