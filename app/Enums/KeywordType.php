<?php

namespace App\Enums;

enum KeywordType: string
{
    case Main = 'main';
    case Exact = 'exact';
    case Broad = 'broad';
    case Negative = 'negative';
    case HighPriority = 'high_priority';
    case LowPriority = 'low_priority';

    public function isPositive(): bool
    {
        return in_array($this, [self::Main, self::Exact, self::Broad], true);
    }
}
