<?php

use App\Enums\UserRole;
use App\Models\CompanySource;
use App\Models\Source;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('страница источников показывает реестр с состоянием компании', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    CompanySource::create(['company_id' => $admin->company_id, 'source_id' => Source::where('code', 'eis')->value('id'), 'is_active' => true]);

    $this->actingAs($admin)
        ->get(route('sources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sources/Index')
            ->has('sources', 2)
            ->where('sources.0.enabled', fn ($v) => is_bool($v)));
});

it('включает и выключает источник', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    $source = Source::where('code', 'eis')->first();

    $this->actingAs($admin)
        ->patch(route('sources.update', $source), ['enabled' => true])
        ->assertRedirect();

    expect(CompanySource::withoutTenancy()->where('company_id', $admin->company_id)->where('source_id', $source->id)->value('is_active'))->toBeTrue();

    $this->actingAs($admin)->patch(route('sources.update', $source), ['enabled' => false])->assertRedirect();
    expect(CompanySource::withoutTenancy()->where('company_id', $admin->company_id)->where('source_id', $source->id)->value('is_active'))->toBeFalse();
});

it('лимит тарифа на число включённых источников', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    $admin->company->activeSubscription()->plan->update(['max_sources' => 1]);

    $eis = Source::where('code', 'eis')->first();
    $manual = Source::where('code', 'manual')->first();

    $this->actingAs($admin)->patch(route('sources.update', $eis), ['enabled' => true])->assertRedirect();
    $this->actingAs($admin)
        ->patch(route('sources.update', $manual), ['enabled' => true])
        ->assertSessionHasErrors('enabled');
});

it('менеджеру запрещено', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);

    $this->actingAs($manager)->get(route('sources.index'))->assertForbidden();
});
