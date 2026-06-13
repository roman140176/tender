<?php

namespace App\Scoring;

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;

final readonly class ScoreResult
{
    /**
     * @param  list<array{rule: string, points: int, detail: string}>  $reason
     * @param  list<array{value: string, type: string, field: string}>  $matchedKeywords
     * @param  list<array{value: string, type: string, field: string}>  $matchedNegativeKeywords
     * @param  list<string>  $riskFlags
     */
    public function __construct(
        public int $score,
        public RelevanceLevel $level,
        public ActionRecommendation $recommendation,
        public array $reason,
        public array $matchedKeywords,
        public array $matchedNegativeKeywords,
        public array $riskFlags,
        public bool $excluded,
    ) {}
}
