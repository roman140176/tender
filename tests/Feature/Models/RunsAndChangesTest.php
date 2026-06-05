<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\SourceCollectRun;
use App\Models\Tender;

it('пишет лог сбора и diff изменений закупки', function () {
    $tender = Tender::factory()->create();
    $run = SourceCollectRun::create([
        'source_id' => $tender->source_id,
        'trigger' => RunTrigger::Manual,
        'status' => RunStatus::Running,
        'started_at' => now(),
    ]);

    $tender->changes()->create([
        'source_collect_run_id' => $run->id,
        'field' => 'price',
        'old_value' => '100000.00',
        'new_value' => '120000.00',
    ]);

    expect($tender->changes)->toHaveCount(1)
        ->and($run->trigger)->toBe(RunTrigger::Manual);
});

it('radar_run принадлежит профилю и компании', function () {
    $profile = SearchProfile::factory()->create();
    $run = RadarRun::create([
        'company_id' => $profile->company_id,
        'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Ok,
        'started_at' => now(),
    ]);
    expect($run->searchProfile->is($profile))->toBeTrue();
});
