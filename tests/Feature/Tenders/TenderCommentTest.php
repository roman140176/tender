<?php

use App\Enums\RelevanceLevel;
use App\Enums\UserRole;
use App\Models\CompanyTender;
use App\Models\Tender;
use App\Models\TenderComment;
use App\Models\User;

function commentCt(User $user): CompanyTender
{
    return CompanyTender::withoutTenancy()->create([
        'company_id' => $user->company_id, 'tender_id' => Tender::factory()->create()->id,
        'best_score' => 50, 'best_level' => RelevanceLevel::Weak, 'first_matched_at' => now(),
    ]);
}

it('менеджер добавляет комментарий с автором и company_id', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $ct = commentCt($manager);

    $this->actingAs($manager)
        ->post(route('tenders.comment', $ct), ['comment' => 'Подходит, беру в работу'])
        ->assertRedirect();

    $c = TenderComment::withoutTenancy()->sole();
    expect($c->comment)->toBe('Подходит, беру в работу')
        ->and($c->user_id)->toBe($manager->id)
        ->and($c->company_id)->toBe($manager->company_id)
        ->and($c->tender_id)->toBe($ct->tender_id);
});

it('viewer не может комментировать (403)', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $ct = commentCt($viewer);

    $this->actingAs($viewer)->post(route('tenders.comment', $ct), ['comment' => 'x'])->assertForbidden();
    expect(TenderComment::withoutTenancy()->count())->toBe(0);
});

it('пустой комментарий отклоняется', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $ct = commentCt($manager);

    $this->actingAs($manager)->post(route('tenders.comment', $ct), ['comment' => ''])->assertSessionHasErrors('comment');
});
