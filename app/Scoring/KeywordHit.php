<?php

namespace App\Scoring;

use App\Enums\KeywordType;

final readonly class KeywordHit
{
    /** @param 'title'|'description'|null $field  поле, где сработал ключ (title приоритетнее) */
    public function __construct(
        public string $value,
        public KeywordType $type,
        public ?string $field,
    ) {}

    public function matched(): bool
    {
        return $this->field !== null;
    }
}
