<?php

use App\Enums\KeywordType;
use App\Models\Company;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Scoring\CandidateQuery;
use Illuminate\Support\Carbon;

function buildCandidates(SearchProfile $profile, array $enabledSourceIds, ?string $since = null)
{
    return app(CandidateQuery::class)
        ->build($profile, $profile->keywords()->get(), $enabledSourceIds, $since ? Carbon::parse($since) : null)
        ->pluck('id');
}

it('FTS-ветка ловит морфологию (склонение) по non-exact ключу', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    // 'краны' (5 симв., >4) идёт ТОЛЬКО в FTS-ветку (не в ILIKE), и substring 'краны' НЕ содержится
    // в 'кранов' — значит хит ловится исключительно стеммером, ветка изолирована.
    Keyword::factory()->for($profile)->create(['value' => 'краны', 'type' => KeywordType::Main]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов мостовых', 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка насосов', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('trigram/ILIKE-ветка ловит аббревиатуру в названии (минует FTS)', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'КМД', 'type' => KeywordType::Main]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Изготовление КМД для каркаса', 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Ремонт кровли', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('exact-фраза попадает в ILIKE-ветку по названию', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'краны мостовые', 'type' => KeywordType::Exact]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка: краны мостовые опорные', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('owner-scope: видны глобальные и свои manual, чужие manual — нет', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $global = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);
    $mine = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => $company->id]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => $other->id]);

    expect(buildCandidates($profile, [$source->id])->sort()->values()->all())->toBe([$global->id, $mine->id]);
});

it('source-scope: закупка из не-включённого источника не кандидат', function () {
    $enabled = Source::factory()->create();
    $disabled = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $hit = Tender::factory()->create(['source_id' => $enabled->id, 'title' => 'Краны', 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $disabled->id, 'title' => 'Краны', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$enabled->id])->all())->toBe([$hit->id]);
});

it('since-фильтр инкрементального матчинга по updated_at', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $old = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);
    $old->forceFill(['updated_at' => now()->subDays(3)])->save();
    $fresh = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id], now()->subDay()->toDateTimeString())->all())->toBe([$fresh->id]);
});

it('без включённых источников — нет кандидатов', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [])->all())->toBe([]);
});

it('фильтр deadline_min_days (§4.2): закупка с близким дедлайном не кандидат', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create(['deadline_min_days' => 7]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $ok = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->addDays(10), 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->addDays(3), 'owner_company_id' => null]);
    $noDeadline = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => null, 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->sort()->values()->all())->toBe([$ok->id, $noDeadline->id]);
});

it('просроченный дедлайн отсекается при deadline_min_days=0 (дефолт)', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create(['deadline_min_days' => 0]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $future = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->addDay(), 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->subDay(), 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$future->id]);
});

it('ILIKE-ветка нормализует ё→е и регистр (предфильтр не теряет кандидата под exact-фразу)', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'Ёлочные Гирлянды', 'type' => KeywordType::Exact]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Закупка елочные гирлянды уличные', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('истинная слепая зона: exact-фраза только в описании НЕ становится кандидатом', function () {
    // Единственная положительная зацепка — exact-фраза, и только в описании. Exact идёт в ILIKE-ветку
    // (только title), в FTS-ветку не попадает → кандидата нет. Это задокументированный компромисс §7.6.
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'аренда спецтехники', 'type' => KeywordType::Exact]);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка строительных материалов',
        'description' => 'в составе аренда спецтехники на объекте',
        'owner_company_id' => null,
    ]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([]);
});
