<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;

it('нельзя активировать направление без позитивного ключа', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->create(['search_profile_id' => $profile->id, 'type' => KeywordType::Negative, 'value' => 'аренда']);

    $this->actingAs($admin)
        ->post(route('profiles.activate', $profile))
        ->assertSessionHasErrors('activation');

    expect($profile->refresh()->is_active)->toBeFalse();
});

it('активация и деактивация с позитивным ключом', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->create(['search_profile_id' => $profile->id, 'type' => KeywordType::Main, 'value' => 'кран']);

    $this->actingAs($admin)->post(route('profiles.activate', $profile))->assertRedirect();
    expect($profile->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->post(route('profiles.deactivate', $profile))->assertRedirect();
    expect($profile->refresh()->is_active)->toBeFalse();
});

it('чужое направление не активировать (404)', function () {
    $admin = adminWithPlan();
    $foreign = SearchProfile::factory()->create();

    $this->actingAs($admin)->post(route('profiles.activate', $foreign))->assertNotFound();
});
