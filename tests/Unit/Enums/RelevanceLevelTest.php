<?php

use App\Enums\RelevanceLevel;

dataset('score_to_level', [
    'score 100' => [100, RelevanceLevel::High],
    'score 80' => [80, RelevanceLevel::High],
    'score 79' => [79, RelevanceLevel::Medium],
    'score 60' => [60, RelevanceLevel::Medium],
    'score 59' => [59, RelevanceLevel::Weak],
    'score 40' => [40, RelevanceLevel::Weak],
    'score 39' => [39, RelevanceLevel::Low],
    'score 0' => [0, RelevanceLevel::Low],
]);

it('маппит балл в уровень по полуинтервалам', function ($score, $level) {
    expect(RelevanceLevel::fromScore($score))->toBe($level);
})->with('score_to_level');
