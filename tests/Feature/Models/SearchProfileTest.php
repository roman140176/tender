<?php

use App\Enums\KeywordType;
use App\Models\SearchProfile;

it('нельзя активировать профиль без позитивного ключа', function () {
    $profile = SearchProfile::factory()->create();
    $profile->keywords()->create(['value' => 'аренда', 'type' => KeywordType::Negative]);
    expect($profile->canActivate())->toBeFalse();

    $profile->keywords()->create(['value' => 'металлоконструкции', 'type' => KeywordType::Main]);
    expect($profile->refresh()->canActivate())->toBeTrue();
});

it('хранит jsonb-настройки и дефолты', function () {
    $profile = SearchProfile::factory()->create([
        'law_types' => ['fz44', 'fz223'],
        'regions' => ['Москва'],
    ]);
    expect($profile->law_types)->toBe(['fz44', 'fz223'])
        ->and($profile->notification_threshold)->toBe(60)
        ->and($profile->deadline_min_days)->toBe(0)
        ->and($profile->exclude_on_negative_in_title)->toBeFalse();
});
