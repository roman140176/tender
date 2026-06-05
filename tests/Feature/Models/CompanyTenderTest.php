<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Models\Company;
use App\Models\CompanyTender;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderComment;
use App\Models\TenderMatch;
use App\Models\TenderStatusHistory;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

it('хранит per-company проекцию закупки и матч с расшифровкой', function () {
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    $tender = Tender::factory()->create();
    app(TenantContext::class)->set($company->id); // matches() полагается на tenant-scope

    $ct = CompanyTender::create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'status' => WorkflowStatus::New,
        'best_score' => 75,
        'best_level' => RelevanceLevel::Medium,
        'first_matched_at' => now(),
    ]);

    TenderMatch::create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'reason' => [['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'металлоконструкции']],
        'matched_keywords' => [['value' => 'металлоконструкции', 'type' => 'main', 'field' => 'title']],
        'risk_flags' => ['deadline_soon'],
        'action_recommendation' => ActionRecommendation::ReviewDocs,
    ]);

    expect($ct->tender->is($tender))->toBeTrue()
        ->and($ct->matches)->toHaveCount(1)
        ->and($ct->matches->first()->reason[0]['points'])->toBe(20);
});

it('запрещает второй company_tender той же пары', function () {
    $ct = CompanyTender::factory()->create();
    CompanyTender::factory()->create(['company_id' => $ct->company_id, 'tender_id' => $ct->tender_id]);
})->throws(QueryException::class);

it('изолирует company_tenders по тенанту', function () {
    $a = CompanyTender::factory()->create();
    CompanyTender::factory()->create(); // другая компания

    app(TenantContext::class)->set($a->company_id);
    expect(CompanyTender::count())->toBe(1);
});

it('изолирует tender_matches, комментарии и историю по тенанту', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $tender = Tender::factory()->create();
    $user = User::factory()->for($a)->create();

    TenderMatch::factory()->create(['company_id' => $a->id, 'tender_id' => $tender->id]);
    TenderMatch::factory()->create(['company_id' => $b->id, 'tender_id' => $tender->id]);
    TenderComment::create(['company_id' => $a->id, 'tender_id' => $tender->id, 'user_id' => $user->id, 'comment' => 'наш']);
    TenderComment::create(['company_id' => $b->id, 'tender_id' => $tender->id, 'user_id' => $user->id, 'comment' => 'чужой']);
    TenderStatusHistory::create(['company_id' => $a->id, 'tender_id' => $tender->id, 'new_status' => 'interesting']);
    TenderStatusHistory::create(['company_id' => $b->id, 'tender_id' => $tender->id, 'new_status' => 'declined']);

    app(TenantContext::class)->set($a->id);

    expect(TenderMatch::count())->toBe(1)
        ->and(TenderComment::count())->toBe(1)
        ->and(TenderComment::first()->comment)->toBe('наш')
        ->and(TenderStatusHistory::count())->toBe(1);
});

it('запрещает второй матч той же пары (профиль, закупка)', function () {
    $m = TenderMatch::factory()->create();
    TenderMatch::factory()->create([
        'search_profile_id' => $m->search_profile_id,
        'tender_id' => $m->tender_id,
        'company_id' => $m->company_id,
    ]);
})->throws(QueryException::class);

it('matches() без TenantContext бросает исключение', function () {
    $ct = CompanyTender::factory()->create();
    app(TenantContext::class)->set(null);
    $ct->matches;
})->throws(RuntimeException::class);
