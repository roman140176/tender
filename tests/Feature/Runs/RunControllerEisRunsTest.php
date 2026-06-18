<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('суперадмин видит глобальные ЕИС-раны (company_id=NULL) в eisRuns', function () {
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $eisRun = SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => null,
        'trigger' => RunTrigger::Schedule,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'fetched_count' => 100,
        'new_count' => 10,
        'updated_count' => 2,
        'error_count' => 0,
    ]);

    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('eisRuns', 1)
            ->where('eisRuns.0.id', $eisRun->id));
});

it('менеджер компании не видит ЕИС-раны (company_id=NULL) в своём collectRuns', function () {
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => null,
        'trigger' => RunTrigger::Schedule,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'fetched_count' => 100,
        'new_count' => 10,
        'updated_count' => 2,
        'error_count' => 0,
    ]);

    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('collectRuns', 0)
            ->has('eisRuns', 0));
});

it('обычный пользователь видит свои company-scoped collectRuns', function () {
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $admin = adminWithPlan();

    $companyRun = SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => $admin->company_id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(3),
        'finished_at' => now(),
        'fetched_count' => 5,
        'new_count' => 2,
        'updated_count' => 0,
        'error_count' => 0,
    ]);

    $this->actingAs($admin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('collectRuns', 1)
            ->where('collectRuns.0.id', $companyRun->id)
            ->has('eisRuns', 0));
});
