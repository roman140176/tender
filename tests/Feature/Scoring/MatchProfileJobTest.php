<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Keyword;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Scoring\MatchProfileService;

beforeEach(function () {
    Source::factory()->create(['code' => 'manual']);
});

it('исполняет матчинг активного профиля по id', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    CompanySource::withoutTenancy()->create(['company_id' => $company->id, 'source_id' => $source->id, 'is_active' => true]);
    $profile = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    (new MatchProfileJob($profile->id, RunTrigger::Import->value))->handle(app(MatchProfileService::class));

    expect(RadarRun::withoutTenancy()->where('search_profile_id', $profile->id)->count())->toBe(1);
});

it('тихо выходит на удалённом профиле', function () {
    (new MatchProfileJob(999999, RunTrigger::Import->value))->handle(app(MatchProfileService::class));

    expect(RadarRun::withoutTenancy()->count())->toBe(0);
});

it('тихо выходит на неактивном профиле', function () {
    $profile = SearchProfile::factory()->create(['is_active' => false]);

    (new MatchProfileJob($profile->id, RunTrigger::Import->value))->handle(app(MatchProfileService::class));

    expect(RadarRun::withoutTenancy()->count())->toBe(0);
});
