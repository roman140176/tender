<?php

use App\Enums\ActionRecommendation;
use App\Enums\LawType;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Models\Company;
use App\Models\CompanyTender;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenders\TenderListQuery;

/** Создаёт пару company_tender + один tender_match. */
function matchedTender(Company $company, array $tenderAttrs = [], array $ctAttrs = [], array $matchAttrs = []): CompanyTender
{
    $tender = Tender::factory()->create($tenderAttrs);
    $ct = CompanyTender::withoutTenancy()->create(array_merge([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'best_score' => 70,
        'best_level' => RelevanceLevel::Medium,
        'first_matched_at' => now(),
    ], $ctAttrs));
    $profile = SearchProfile::factory()->for($company)->create();
    TenderMatch::withoutTenancy()->create(array_merge([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'score' => 70,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
    ], $matchAttrs));

    return $ct;
}

function listFor(Company $company, array $filters = [])
{
    app(TenantContext::class)->set($company->id);

    return app(TenderListQuery::class)->paginate($filters, perPage: 20);
}

it('отдаёт только закупки своей компании (изоляция)', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    matchedTender($a);
    matchedTender($b);

    expect(listFor($a)->total())->toBe(1);
});

it('скрывает осиротевшие проекции без единого tender_match (follow-up A4 #2)', function () {
    $company = Company::factory()->create();
    matchedTender($company); // с матчем — показывается
    // осиротевшая: company_tender без матчей (исключена по негативу)
    $tender = Tender::factory()->create();
    CompanyTender::withoutTenancy()->create([
        'company_id' => $company->id, 'tender_id' => $tender->id,
        'best_score' => 0, 'best_level' => RelevanceLevel::Low, 'first_matched_at' => now(),
    ]);

    expect(listFor($company)->total())->toBe(1);
});

it('фильтрует по статусу, закону, региону, уровню', function () {
    $company = Company::factory()->create();
    $src = Source::factory()->create();
    matchedTender($company, ['source_id' => $src->id, 'law_type' => LawType::Fz44, 'region' => 'Москва'],
        ['status' => WorkflowStatus::Interesting, 'best_level' => RelevanceLevel::High]);
    matchedTender($company, ['source_id' => $src->id, 'law_type' => LawType::Fz223, 'region' => 'Тверь'],
        ['status' => WorkflowStatus::New, 'best_level' => RelevanceLevel::Low]);

    expect(listFor($company, ['status' => 'interesting'])->total())->toBe(1)
        ->and(listFor($company, ['law_type' => 'fz223'])->total())->toBe(1)
        ->and(listFor($company, ['region' => 'Москва'])->total())->toBe(1)
        ->and(listFor($company, ['level' => 'high'])->total())->toBe(1);
});

it('фильтрует по цене (min/max), источнику и ответственному', function () {
    $company = Company::factory()->create();
    $src1 = Source::factory()->create();
    $src2 = Source::factory()->create();
    $user = User::factory()->for($company)->create();
    matchedTender($company, ['source_id' => $src1->id, 'price' => '500000'], ['assigned_user_id' => $user->id]);
    matchedTender($company, ['source_id' => $src2->id, 'price' => '5000000']);

    expect(listFor($company, ['price_min' => '1000000'])->total())->toBe(1)
        ->and(listFor($company, ['price_max' => '1000000'])->total())->toBe(1)
        ->and(listFor($company, ['source_id' => $src1->id])->total())->toBe(1)
        ->and(listFor($company, ['assignee' => $user->id])->total())->toBe(1);
});

it('фильтрует по направлению (профилю) и текстовому поиску', function () {
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    $tender = Tender::factory()->create(['title' => 'Поставка кранов мостовых']);
    CompanyTender::withoutTenancy()->create(['company_id' => $company->id, 'tender_id' => $tender->id,
        'best_score' => 70, 'best_level' => RelevanceLevel::Medium, 'first_matched_at' => now()]);
    TenderMatch::withoutTenancy()->create(['company_id' => $company->id, 'tender_id' => $tender->id,
        'search_profile_id' => $profile->id, 'score' => 70, 'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs]);
    matchedTender($company, ['title' => 'Ремонт кровли']); // другой профиль/закупка

    expect(listFor($company, ['profile_id' => $profile->id])->total())->toBe(1)
        ->and(listFor($company, ['q' => 'кранов'])->total())->toBe(1);
});

it('сортирует по best_score убыванию и пагинирует', function () {
    $company = Company::factory()->create();
    matchedTender($company, [], ['best_score' => 30]);
    matchedTender($company, [], ['best_score' => 90]);

    $page = listFor($company);
    expect($page->total())->toBe(2)
        ->and($page->items()[0]->best_score)->toBe(90); // сильнейший первым
});

it('нейтрализует wildcard-символы (%) в текстовом поиске', function () {
    $company = Company::factory()->create();
    matchedTender($company, ['title' => 'цена 100% гарантия']);
    matchedTender($company, ['title' => 'цена 100 рублей']);

    // '100%' экранируется → ищется литеральная подстрока "100%", а не "100" + любой хвост
    expect(listFor($company, ['q' => '100%'])->total())->toBe(1);
});

it('применяет несколько фильтров одновременно (AND)', function () {
    $company = Company::factory()->create();
    matchedTender($company, ['title' => 'Поставка кранов', 'price' => '2000000'],
        ['status' => WorkflowStatus::Interesting]);
    matchedTender($company, ['title' => 'Поставка кранов', 'price' => '300000'],
        ['status' => WorkflowStatus::Interesting]); // ниже price_min
    matchedTender($company, ['title' => 'Ремонт кровли', 'price' => '5000000'],
        ['status' => WorkflowStatus::New]); // другой статус и текст

    expect(listFor($company, ['status' => 'interesting', 'q' => 'кран', 'price_min' => '1000000'])->total())->toBe(1);
});
