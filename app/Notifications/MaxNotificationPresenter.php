<?php

namespace App\Notifications;

use App\Models\TenderMatch;
use App\Scoring\MatchReasonPresenter;

/**
 * Рендерит MAX-уведомление о матче по формату §14.1 спеки.
 * Разметка — MAX HTML (format:'html'). Все динамические строки
 * экранируются htmlspecialchars (ENT_QUOTES, UTF-8), чтобы спецсимволы
 * из названий/деталей не ломали разметку. Экранируются только HTML-спецсимволы:
 * & < > (и кавычки для href-атрибутов). MarkdownV2-escape не используется.
 */
final class MaxNotificationPresenter
{
    public static function render(TenderMatch $match): string
    {
        $tender = $match->tender;
        $source = $tender->source;

        $title = self::esc((string) $tender->title);
        $price = $tender->price === null
            ? 'не указана'
            : number_format((float) $tender->price, 2, ',', ' ').' ₽';
        $region = $tender->region !== null && $tender->region !== ''
            ? self::esc((string) $tender->region)
            : 'не указан';
        $deadline = $tender->deadline_at?->format('d.m.Y H:i') ?? 'не указан';
        $sourceName = $source !== null
            ? self::esc((string) $source->name)
            : 'не указан';
        $url = self::esc((string) $tender->source_url);

        $lines = [];
        $lines[] = '<b>🔔 '.$title.'</b>';
        $lines[] = '';
        $lines[] = '<b>Цена:</b> '.self::esc($price);
        $lines[] = '<b>Регион:</b> '.$region;
        $lines[] = '<b>Срок подачи:</b> '.self::esc($deadline);
        $lines[] = '<b>Источник:</b> '.$sourceName;
        $lines[] = '<b>Релевантность:</b> '.self::esc($match->level->label()).' ('.$match->score.')';

        $reasonLines = MatchReasonPresenter::lines($match->reason);
        if ($reasonLines !== []) {
            $lines[] = '';
            $lines[] = '<b>Почему подходит:</b>';
            foreach ($reasonLines as $reason) {
                $lines[] = '• '.self::esc($reason);
            }
        }

        $risks = MatchReasonPresenter::risks($match->risk_flags);
        if ($risks !== []) {
            $lines[] = '';
            $lines[] = '<b>Риски:</b>';
            foreach ($risks as $risk) {
                $lines[] = '• '.self::esc($risk);
            }
        }

        $lines[] = '';
        $lines[] = '<b>Рекомендация:</b> '.self::esc($match->action_recommendation->label());
        $lines[] = '';
        $lines[] = '<a href="'.$url.'">Открыть закупку</a>';

        return implode("\n", $lines);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
