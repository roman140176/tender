<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\Source;
use App\Models\SourceCollectRun;

it('ран сбора может принадлежать компании (manual-импорт)', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create(['code' => 'manual']);

    $run = SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => $company->id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Running,
        'started_at' => now(),
    ]);

    expect($run->refresh()->company_id)->toBe($company->id);
});
