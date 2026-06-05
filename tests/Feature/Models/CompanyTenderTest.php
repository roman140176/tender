<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Models\Company;
use App\Models\CompanyTender;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
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
