<?php

use App\Enums\KeywordType;
use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\CompanyTender;
use App\Models\Keyword;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-06-13 12:00:00');
    // Источник manual должен существовать (его сервис добавляет к включённым автоматически).
    Source::factory()->create(['code' => 'manual']);
    // Чистый scoring-тест не должен зависеть от redis: NotifyJob уходит на connection 'notifications'
    // (driver=redis) и не падает в sync-очередь теста. Fake убирает скрытую redis-зависимость.
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function activeProfile(Company $company, array $keywords): SearchProfile
{
    $profile = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    foreach ($keywords as [$value, $type]) {
        Keyword::factory()->for($profile)->create(['value' => $value, 'type' => $type]);
    }

    return $profile->refresh();
}

function enableSource(Company $company, Source $source): void
{
    CompanySource::withoutTenancy()->create([
        'company_id' => $company->id,
        'source_id' => $source->id,
        'is_active' => true,
    ]);
}

function runMatch(SearchProfile $profile, RunTrigger $trigger = RunTrigger::Import): RadarRun
{
    return app(MatchProfileService::class)->run($profile, $trigger);
}

it('создаёт матч и проекцию company_tender по релевантной закупке', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);

    $tender = Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка кранов мостовых',
        'price' => '1500000',
        'deadline_at' => now()->addDays(3), // нейтральная зона: без deadline_far_bonus (фабрика по умолчанию +10 дней!)
        'owner_company_id' => null,
    ]);

    $run = runMatch($profile);

    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->matched_count)->toBe(1)
        ->and($run->processed_count)->toBe(1);

    $match = TenderMatch::withoutTenancy()->sole();
    expect($match->company_id)->toBe($company->id)
        ->and($match->search_profile_id)->toBe($profile->id)
        ->and($match->radar_run_id)->toBe($run->id)
        ->and($match->score)->toBe(20)
        ->and($match->matched_keywords)->toBe([
            ['value' => 'кран', 'type' => 'main', 'field' => 'title'],
        ]);

    $ct = CompanyTender::withoutTenancy()->sole();
    expect($ct->best_score)->toBe(20)
        ->and($ct->best_level)->toBe(RelevanceLevel::Low)
        ->and($ct->first_matched_at)->not->toBeNull();
});

it('не матчит закупку без положительного попадания ключа', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);

    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Ремонт кровли', 'owner_company_id' => null]);

    $run = runMatch($profile);
    expect($run->matched_count)->toBe(0)
        ->and(TenderMatch::withoutTenancy()->count())->toBe(0)
        ->and(CompanyTender::withoutTenancy()->count())->toBe(0);
});

it('повторный прогон обновляет матч, а не плодит дубль; first_matched_at сохраняется', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    runMatch($profile);
    $firstMatchedAt = CompanyTender::withoutTenancy()->sole()->first_matched_at;

    Carbon::setTestNow('2026-06-20 12:00:00');
    runMatch($profile);

    expect(TenderMatch::withoutTenancy()->count())->toBe(1)
        ->and(CompanyTender::withoutTenancy()->count())->toBe(1)
        ->and(CompanyTender::withoutTenancy()->sole()->first_matched_at->toDateTimeString())
        ->toBe($firstMatchedAt->toDateTimeString());
});

it('best_* = максимум по нескольким профилям одной компании', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);

    $tender = Tender::factory()->create([
        'source_id' => $source->id,
        // Именительная форма фразы: exact матчится ТОЛЬКО смежным substring (не морфологически).
        'title' => 'Поставка: краны мостовые опорные',
        'price' => '2000000',
        'region' => 'Москва',
        'deadline_at' => now()->addDays(3), // нейтральный дедлайн: без +10 (иначе strong=70, weak=30)
        'owner_company_id' => null,
    ]);

    $weak = activeProfile($company, [['кран', KeywordType::Main]]); // keyword_in_title 20
    $strong = activeProfile($company, [['краны мостовые', KeywordType::Exact]]);
    $strong->update(['min_price' => '1000000', 'regions' => ['Москва']]);

    runMatch($weak);
    runMatch($strong->refresh());

    expect(TenderMatch::withoutTenancy()->where('tender_id', $tender->id)->count())->toBe(2);
    $ct = CompanyTender::withoutTenancy()->where('tender_id', $tender->id)->sole();
    // strong: exact_in_title 30 + price_above_min 20 + priority_region 10 = 60 > weak 20
    expect($ct->best_score)->toBe(60)->and($ct->best_level)->toBe(RelevanceLevel::Medium);
});

it('исключение по негативу в названии: матч не создаётся; существующий удаляется, best обнуляется', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);
    $profile->update(['exclude_on_negative_in_title' => true]);
    Keyword::factory()->for($profile)->create(['value' => 'бу', 'type' => KeywordType::Negative]);

    $tender = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    // Первый прогон — матч есть.
    runMatch($profile->refresh());
    expect(TenderMatch::withoutTenancy()->count())->toBe(1);

    // Название меняется — появляется минус-слово в названии.
    $tender->update(['title' => 'Поставка кранов бу']);
    runMatch($profile->refresh());

    expect(TenderMatch::withoutTenancy()->count())->toBe(0)
        ->and(CompanyTender::withoutTenancy()->sole()->best_score)->toBe(0);
});

it('manual-источник включён неявно: своя импортированная закупка матчится без company_sources', function () {
    $company = Company::factory()->create();
    $manual = Source::query()->where('code', 'manual')->sole();
    // company_sources НЕ создаём — manual должен добавиться автоматически.
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);
    Tender::factory()->create(['source_id' => $manual->id, 'title' => 'Поставка кранов', 'owner_company_id' => $company->id]);

    $run = runMatch($profile);
    expect($run->matched_count)->toBe(1);
});

it('работает без TenantContext (как в джобе) и не протекает между компаниями', function () {
    app(TenantContext::class)->set(null);

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($companyA, $source);
    enableSource($companyB, $source);

    $profileA = activeProfile($companyA, [['кран', KeywordType::Main]]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    runMatch($profileA);

    expect(TenderMatch::withoutTenancy()->where('company_id', $companyA->id)->count())->toBe(1)
        ->and(TenderMatch::withoutTenancy()->where('company_id', $companyB->id)->count())->toBe(0);
});
