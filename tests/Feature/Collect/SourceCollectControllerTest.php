<?php

use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Bus;

it('суперадмин может запустить сбор источника из UI', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->post(route('admin.sources.collect', $source))
        ->assertRedirect();

    Bus::assertDispatched(CollectSourceJob::class, function (CollectSourceJob $job) use ($source) {
        return $job->sourceId === $source->id
            && $job->trigger === RunTrigger::Manual->value;
    });
});

it('обычный admin не может запустить сбор из admin-маршрута — 403', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->post(route('admin.sources.collect', $source))
        ->assertForbidden();

    Bus::assertNothingDispatched();
});

it('гость не может запустить сбор из admin-маршрута — redirect to login', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $this->post(route('admin.sources.collect', $source))
        ->assertRedirect(route('login'));

    Bus::assertNothingDispatched();
});

it('суперадмин видит кнопку запуска сбора на странице источников', function () {
    $this->seed(SourceSeeder::class);
    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get(route('admin.sources.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Sources/Index')
            ->has('sources'));
});
