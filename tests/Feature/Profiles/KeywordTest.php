<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;

it('добавляет и удаляет ключи', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'металлоконструкции', 'type' => 'main'])
        ->assertRedirect();

    $keyword = $profile->keywords()->first();
    expect($keyword->value)->toBe('металлоконструкции')
        ->and($keyword->type)->toBe(KeywordType::Main);

    $this->actingAs($admin)->delete(route('keywords.destroy', $keyword))->assertRedirect();
    expect($profile->keywords()->count())->toBe(0);
});

it('дубль ключа того же типа отклоняется валидацией', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->create(['search_profile_id' => $profile->id, 'value' => 'кран', 'type' => KeywordType::Main]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'кран', 'type' => 'main'])
        ->assertSessionHasErrors('value');
});

it('чужой профиль и чужой ключ — 404', function () {
    $admin = adminWithPlan();
    $foreignProfile = SearchProfile::factory()->create();
    $foreignKeyword = Keyword::factory()->create(['search_profile_id' => $foreignProfile->id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $foreignProfile), ['value' => 'x', 'type' => 'main'])
        ->assertNotFound();

    $this->actingAs($admin)->delete(route('keywords.destroy', $foreignKeyword))->assertNotFound();
});

it('неизвестный тип отклоняется', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'x', 'type' => 'wrong'])
        ->assertSessionHasErrors('type');
});
