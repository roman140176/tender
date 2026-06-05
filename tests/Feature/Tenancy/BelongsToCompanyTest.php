<?php

use App\Models\Company;
use App\Models\SearchProfile;
use App\Tenancy\TenantContext;

it('скрывает чужие записи при установленном тенанте и отдаёт все без него', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    SearchProfile::factory()->for($a)->create(['name' => 'A-профиль']);
    SearchProfile::factory()->for($b)->create(['name' => 'B-профиль']);

    app(TenantContext::class)->set($a->id);
    expect(SearchProfile::query()->pluck('name')->all())->toBe(['A-профиль']);

    app(TenantContext::class)->set(null);
    expect(SearchProfile::query()->count())->toBe(2);
})->skip('ждёт Task 7: модель SearchProfile');

it('автозаполняет company_id из контекста при создании', function () {
    $a = Company::factory()->create();
    app(TenantContext::class)->set($a->id);

    $profile = SearchProfile::factory()->create(['company_id' => null]);
    expect($profile->company_id)->toBe($a->id);
})->skip('ждёт Task 7: модель SearchProfile');
