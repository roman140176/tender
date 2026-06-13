<?php

namespace App\Enums;

enum ActionRecommendation: string
{
    case HandOver = 'hand_over';
    case ReviewDocs = 'review_docs';
    case Monitor = 'monitor';
    case Decline = 'decline';

    public function label(): string
    {
        return match ($this) {
            self::HandOver => 'Передать менеджеру',
            self::ReviewDocs => 'Посмотреть ТЗ',
            self::Monitor => 'Мониторить',
            self::Decline => 'Отклонить',
        };
    }
}
