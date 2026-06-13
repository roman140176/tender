<?php

use App\Enums\RelevanceLevel;
use App\Enums\UserRole;
use App\Models\CompanyTender;
use App\Models\Tender;
use App\Models\User;

function assignCt(User $user): CompanyTender
{
    return CompanyTender::withoutTenancy()->create([
        'company_id' => $user->company_id, 'tender_id' => Tender::factory()->create()->id,
        'best_score' => 50, 'best_level' => RelevanceLevel::Weak, 'first_matched_at' => now(),
    ]);
}

it('менеджер назначает ответственного из своей компании', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $colleague = User::factory()->for($manager->company)->create();
    $ct = assignCt($manager);

    $this->actingAs($manager)
        ->patch(route('tenders.assign', $ct), ['assigned_user_id' => $colleague->id])
        ->assertRedirect();

    expect($ct->refresh()->assigned_user_id)->toBe($colleague->id);
});

it('можно снять ответственного (null)', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $ct = assignCt($manager);
    $ct->update(['assigned_user_id' => $manager->id]);

    $this->actingAs($manager)->patch(route('tenders.assign', $ct), ['assigned_user_id' => null])->assertRedirect();
    expect($ct->refresh()->assigned_user_id)->toBeNull();
});

it('нельзя назначить пользователя чужой компании', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $stranger = User::factory()->create(); // другая компания
    $ct = assignCt($manager);

    $this->actingAs($manager)->patch(route('tenders.assign', $ct), ['assigned_user_id' => $stranger->id])->assertSessionHasErrors('assigned_user_id');
    expect($ct->refresh()->assigned_user_id)->toBeNull();
});

it('нельзя назначить неактивного пользователя своей компании', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $inactive = User::factory()->for($manager->company)->create(['is_active' => false]);
    $ct = assignCt($manager);

    $this->actingAs($manager)
        ->patch(route('tenders.assign', $ct), ['assigned_user_id' => $inactive->id])
        ->assertSessionHasErrors('assigned_user_id');
    expect($ct->refresh()->assigned_user_id)->toBeNull();
});

it('viewer не может назначать (403)', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $ct = assignCt($viewer);

    $this->actingAs($viewer)->patch(route('tenders.assign', $ct), ['assigned_user_id' => $viewer->id])->assertForbidden();
});
