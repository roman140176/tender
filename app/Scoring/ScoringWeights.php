<?php

namespace App\Scoring;

use App\Models\SearchProfile;
use InvalidArgumentException;

final readonly class ScoringWeights
{
    /** @param array<string, int> $values */
    private function __construct(private array $values) {}

    public static function for(SearchProfile $profile): self
    {
        /** @var array<string, int> $defaults */
        $defaults = config('scoring.weights');
        /** @var array<string, mixed> $override */
        $override = $profile->scoring_weights ?? [];

        $merged = $defaults;
        foreach ($defaults as $key => $_) {
            if (array_key_exists($key, $override) && is_numeric($override[$key])) {
                $merged[$key] = (int) $override[$key];
            }
        }

        return new self($merged);
    }

    public function get(string $key): int
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Неизвестный вес скоринга: {$key}");
        }

        return $this->values[$key];
    }
}
