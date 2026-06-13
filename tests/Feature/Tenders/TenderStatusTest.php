<?php

use App\Enums\RelevanceLevel;
use App\Enums\UserRole;
use App\Enums\WorkflowStatus;
use App\Models\CompanyTender;
use App\Models\Tender;
use App\Models\TenderStatusHistory;
use App\Models\User;

function makeCt(User $user): CompanyTender
{
    return CompanyTender::withoutTenancy()->create([
        'company_id' => $user->company_id, 'tender_id' => Tender::factory()->create()->id,
        'status' => WorkflowStatus::New, 'best_score' => 50, 'best_level' => RelevanceLevel::Weak, 'first_matched_at' => now(),
    ]);
}

it('менеджер меняет статус и пишется история с автором', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $ct = makeCt($manager);

    $this->actingAs($manager)
        ->patch(route('tenders.status', $ct), ['status' => 'interesting'])
        ->assertRedirect();

    expect($ct->refresh()->status)->toBe(WorkflowStatus::Interesting);
    $h = TenderStatusHistory::withoutTenancy()->sole();
    expect($h->old_status)->toBe('new')->and($h->new_status)->toBe('interesting')->and($h->user_id)->toBe($manager->id);
});

it('viewer не может менять статус (403)', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $ct = makeCt($viewer);

    $this->actingAs($viewer)->patch(route('tenders.status', $ct), ['status' => 'interesting'])->assertForbidden();
    expect($ct->refresh()->status)->toBe(WorkflowStatus::New);
});

it('невалидный статус отклоняется', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $ct = makeCt($manager);

    $this->actingAs($manager)->patch(route('tenders.status', $ct), ['status' => 'bogus'])->assertSessionHasErrors('status');
});

it('нельзя менять статус закупки чужой компании (404)', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $foreign = CompanyTender::withoutTenancy()->create([
        'company_id' => User::factory()->create()->company_id, 'tender_id' => Tender::factory()->create()->id,
        'status' => WorkflowStatus::New, 'best_score' => 10, 'best_level' => RelevanceLevel::Low, 'first_matched_at' => now(),
    ]);

    $this->actingAs($manager)->patch(route('tenders.status', $foreign), ['status' => 'interesting'])->assertNotFound();
});

it('повторная отправка того же статуса не создаёт строку истории', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $ct = makeCt($manager); // status = New

    $this->actingAs($manager)
        ->patch(route('tenders.status', $ct), ['status' => 'new'])
        ->assertRedirect();

    expect(TenderStatusHistory::withoutTenancy()->count())->toBe(0)
        ->and($ct->refresh()->status)->toBe(WorkflowStatus::New);
});
