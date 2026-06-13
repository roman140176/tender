<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('показывает radar_runs и source_collect_runs своей компании, изолируя чужие', function () {
    $admin = adminWithPlan();
    $other = Company::factory()->create();
    $source = Source::factory()->create();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    RadarRun::withoutTenancy()->create(['company_id' => $admin->company_id, 'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Import, 'status' => RunStatus::Ok, 'started_at' => now(), 'matched_count' => 3]);
    RadarRun::withoutTenancy()->create(['company_id' => $other->id, 'search_profile_id' => SearchProfile::factory()->create(['company_id' => $other->id])->id,
        'trigger' => RunTrigger::Import, 'status' => RunStatus::Ok, 'started_at' => now()]);
    SourceCollectRun::create(['source_id' => $source->id, 'company_id' => $admin->company_id,
        'trigger' => RunTrigger::Import, 'status' => RunStatus::Ok, 'started_at' => now(), 'new_count' => 5]);
    SourceCollectRun::create(['source_id' => $source->id, 'company_id' => $other->id,
        'trigger' => RunTrigger::Import, 'status' => RunStatus::Ok, 'started_at' => now()]);

    $this->actingAs($admin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('radarRuns', 1)
            ->has('collectRuns', 1));
});

it('viewer тоже видит логи', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $this->actingAs($viewer)->get(route('runs.index'))->assertOk();
});
