<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Jobs\NotifyJob;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
    Source::factory()->create(['code' => 'manual']);
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function notifyProfile(Company $company, array $keywords, int $threshold): SearchProfile
{
    $profile = SearchProfile::factory()->for($company)->create([
        'is_active' => true,
        'notification_threshold' => $threshold,
    ]);
    foreach ($keywords as [$value, $type]) {
        Keyword::factory()->for($profile)->create(['value' => $value, 'type' => $type]);
    }

    return $profile->refresh();
}

function enableNotifySource(Company $company, Source $source): void
{
    CompanySource::withoutTenancy()->create([
        'company_id' => $company->id,
        'source_id' => $source->id,
        'is_active' => true,
    ]);
}

it('диспатчит NotifyJob, когда score >= notification_threshold', function () {
    $company = Company::factory()->create(['max_chat_id' => '123']);
    $source = Source::factory()->create();
    enableNotifySource($company, $source);

    // exact_in_title 30 + price_above_min 20 + priority_region 10 = 60 >= порог 60
    $profile = notifyProfile($company, [['краны мостовые', KeywordType::Exact]], threshold: 60);
    $profile->update(['min_price' => '1000000', 'regions' => ['Москва']]);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка: краны мостовые опорные',
        'price' => '2000000',
        'region' => 'Москва',
        'deadline_at' => now()->addDays(3),
        'owner_company_id' => null,
    ]);

    app(MatchProfileService::class)->run($profile->refresh(), RunTrigger::Import);

    $match = TenderMatch::withoutTenancy()->sole();
    expect($match->score)->toBe(60);

    Queue::assertPushed(NotifyJob::class, function (NotifyJob $job) use ($match) {
        return $job->tenderMatchId === $match->id;
    });
});

it('НЕ диспатчит NotifyJob, когда у компании нет max_chat_id (хоть score >= threshold)', function () {
    $company = Company::factory()->create(['max_chat_id' => null]);
    $source = Source::factory()->create();
    enableNotifySource($company, $source);

    // тот же набор правил, что и в happy-path: exact_in_title 30 + price_above_min 20 + priority_region 10 = 60 >= порог 60
    $profile = notifyProfile($company, [['краны мостовые', KeywordType::Exact]], threshold: 60);
    $profile->update(['min_price' => '1000000', 'regions' => ['Москва']]);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка: краны мостовые опорные',
        'price' => '2000000',
        'region' => 'Москва',
        'deadline_at' => now()->addDays(3),
        'owner_company_id' => null,
    ]);

    app(MatchProfileService::class)->run($profile->refresh(), RunTrigger::Import);

    // матч создан со score 60 — значит no-dispatch именно из-за отсутствия chat_id, а не из-за скоринга
    expect(TenderMatch::withoutTenancy()->sole()->score)->toBe(60);
    Queue::assertNotPushed(NotifyJob::class);
});

it('НЕ диспатчит NotifyJob, когда score < notification_threshold', function () {
    $company = Company::factory()->create(['max_chat_id' => '123']);
    $source = Source::factory()->create();
    enableNotifySource($company, $source);

    // keyword_in_title 20 < порог 60
    $profile = notifyProfile($company, [['кран', KeywordType::Main]], threshold: 60);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка кранов',
        'deadline_at' => now()->addDays(3),
        'owner_company_id' => null,
    ]);

    app(MatchProfileService::class)->run($profile, RunTrigger::Import);

    expect(TenderMatch::withoutTenancy()->sole()->score)->toBe(20);
    Queue::assertNotPushed(NotifyJob::class);
});
