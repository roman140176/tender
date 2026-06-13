<?php

use App\Models\SearchProfile;
use App\Scoring\ScoringWeights;

it('возвращает дефолты, когда override профиля пуст', function () {
    $profile = SearchProfile::factory()->make(['company_id' => 1, 'scoring_weights' => null]);

    $weights = ScoringWeights::for($profile);

    expect($weights->get('exact_in_title'))->toBe(30)
        ->and($weights->get('keyword_in_description'))->toBe(15)
        ->and($weights->get('deadline_far_days'))->toBe(5);
});

it('частично мержит override поверх дефолтов', function () {
    $profile = SearchProfile::factory()->make([
        'company_id' => 1,
        'scoring_weights' => ['exact_in_title' => 50, 'deadline_soon_days' => 3],
    ]);

    $weights = ScoringWeights::for($profile);

    expect($weights->get('exact_in_title'))->toBe(50)         // переопределён
        ->and($weights->get('deadline_soon_days'))->toBe(3)   // переопределён
        ->and($weights->get('keyword_in_title'))->toBe(20);   // дефолт сохранён
});

it('игнорирует неизвестные ключи и не-числа в override', function () {
    $profile = SearchProfile::factory()->make([
        'company_id' => 1,
        'scoring_weights' => ['unknown_key' => 99, 'exact_in_title' => 'abc'],
    ]);

    $weights = ScoringWeights::for($profile);

    expect($weights->get('exact_in_title'))->toBe(30)
        ->and(fn () => $weights->get('unknown_key'))->toThrow(InvalidArgumentException::class);
});
