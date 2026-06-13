<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyTender;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('отдаёт карточку с объяснением балла, рисками и рекомендацией', function () {
    $admin = adminWithPlan();
    $tender = Tender::factory()->create(['title' => 'Поставка кранов мостовых']);
    $ct = CompanyTender::withoutTenancy()->create([
        'company_id' => $admin->company_id, 'tender_id' => $tender->id,
        'best_score' => 50, 'best_level' => RelevanceLevel::Weak, 'first_matched_at' => now(),
    ]);
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id, 'name' => 'Краны']);
    TenderMatch::withoutTenancy()->create([
        'company_id' => $admin->company_id, 'tender_id' => $tender->id, 'search_profile_id' => $profile->id,
        'score' => 50, 'level' => RelevanceLevel::Weak, 'action_recommendation' => ActionRecommendation::Monitor,
        'reason' => [['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'краны']],
        'matched_keywords' => [['value' => 'краны', 'type' => 'main', 'field' => 'title']],
        'risk_flags' => ['no_price'],
    ]);

    $this->actingAs($admin)
        ->get(route('tenders.show', $ct))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenders/Show')
            ->where('tender.title', 'Поставка кранов мостовых')
            ->where('companyTender.best_score', 50)
            ->where('match.reasonLines.0', 'Ключ в названии: «краны» (+20)')
            ->where('match.risks.0', 'Цена не указана')
            ->where('match.recommendation', 'Мониторить')
            ->has('match.profiles', 1)
            ->where('match.matchedKeywords.0.value', 'краны')
            ->where('canManage', true));
});

it('изолирует карточку чужой компании (404)', function () {
    $admin = adminWithPlan();
    $other = Company::factory()->create();
    $tender = Tender::factory()->create();
    $foreign = CompanyTender::withoutTenancy()->create([
        'company_id' => $other->id, 'tender_id' => $tender->id,
        'best_score' => 50, 'best_level' => RelevanceLevel::Weak, 'first_matched_at' => now(),
    ]);

    $this->actingAs($admin)->get(route('tenders.show', $foreign))->assertNotFound();
});

it('viewer видит карточку, но canManage=false', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $tender = Tender::factory()->create();
    $ct = CompanyTender::withoutTenancy()->create([
        'company_id' => $viewer->company_id, 'tender_id' => $tender->id,
        'best_score' => 10, 'best_level' => RelevanceLevel::Low, 'first_matched_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('tenders.show', $ct))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenders/Show')
            ->where('canManage', false)
            ->where('match', null)
            ->where('assignableUsers', []));
});
