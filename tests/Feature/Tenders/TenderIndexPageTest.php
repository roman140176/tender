<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Enums\UserRole;
use App\Enums\WorkflowStatus;
use App\Models\CompanyTender;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function seedMatched(User $user, array $tenderAttrs = [], array $ctAttrs = []): CompanyTender
{
    $tender = Tender::factory()->create($tenderAttrs);
    $ct = CompanyTender::withoutTenancy()->create(array_merge([
        'company_id' => $user->company_id, 'tender_id' => $tender->id,
        'best_score' => 70, 'best_level' => RelevanceLevel::Medium, 'first_matched_at' => now(),
    ], $ctAttrs));
    $profile = SearchProfile::factory()->create(['company_id' => $user->company_id]);
    TenderMatch::withoutTenancy()->create([
        'company_id' => $user->company_id, 'tender_id' => $tender->id, 'search_profile_id' => $profile->id,
        'score' => 70, 'level' => RelevanceLevel::Medium, 'action_recommendation' => ActionRecommendation::ReviewDocs,
    ]);

    return $ct;
}

it('гость перенаправляется на логин', function () {
    $this->get(route('tenders.index'))->assertRedirect(route('login'));
});

it('viewer видит список закупок', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    seedMatched($viewer, ['title' => 'Поставка кранов']);

    $this->actingAs($viewer)
        ->get(route('tenders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenders/Index')
            ->has('tenders.data', 1)
            ->where('tenders.data.0.tender.title', 'Поставка кранов')
            ->has('filterOptions.statuses')
            ->has('filterOptions.sources'));
});

it('фильтр по статусу применяется через query', function () {
    $admin = adminWithPlan();
    seedMatched($admin, [], ['status' => WorkflowStatus::Interesting]);
    seedMatched($admin, [], ['status' => WorkflowStatus::New]);

    $this->actingAs($admin)
        ->get(route('tenders.index', ['status' => 'interesting']))
        ->assertInertia(fn (Assert $page) => $page->has('tenders.data', 1)->where('filters.status', 'interesting'));
});
