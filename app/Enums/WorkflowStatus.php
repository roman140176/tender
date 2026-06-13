<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case New = 'new';
    case Interesting = 'interesting';
    case ReviewDocs = 'review_docs';
    case HandedOver = 'handed_over';
    case InProgress = 'in_progress';
    case Declined = 'declined';
    case Irrelevant = 'irrelevant';
    case Archived = 'archived';
    case Participating = 'participating';
    case NotParticipating = 'not_participating';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::Interesting => 'Интересная',
            self::ReviewDocs => 'Посмотреть ТЗ',
            self::HandedOver => 'Передана менеджеру',
            self::InProgress => 'В работе',
            self::Declined => 'Отклонена',
            self::Irrelevant => 'Нерелевантная',
            self::Archived => 'Архив',
            self::Participating => 'Участвуем',
            self::NotParticipating => 'Не участвуем',
            self::Won => 'Выиграли',
            self::Lost => 'Проиграли',
        };
    }

    /** @return array<string, string> value => русский лейбл */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
