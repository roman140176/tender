<?php

namespace App\Enums;

enum RelevanceLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Weak = 'weak';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Высокая',
            self::Medium => 'Средняя',
            self::Weak => 'Слабая',
            self::Low => 'Низкая',
        };
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::High,
            $score >= 60 => self::Medium,
            $score >= 40 => self::Weak,
            default => self::Low,
        };
    }
}
