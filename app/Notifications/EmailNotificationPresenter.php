<?php

namespace App\Notifications;

use App\Models\TenderMatch;
use App\Scoring\MatchReasonPresenter;

/**
 * Готовит данные матча для email-вью (resources/views/mail/tender-notification.blade.php).
 * Возвращает уже отформатированные строки/метки (цена, регион, срок, причины, риски).
 * Экранирование НЕ делает — это задача Blade (авто-экранирование {{ }}).
 * Ссылка отдаётся как ($url, $urlIsSafe): кликабельной её делаем только для http(s).
 */
final class EmailNotificationPresenter
{
    /**
     * @return array{
     *     title: string,
     *     price: string,
     *     region: string,
     *     deadline: string,
     *     source: string,
     *     level: string,
     *     score: int,
     *     recommendation: string,
     *     reasons: list<string>,
     *     risks: list<string>,
     *     url: string,
     *     urlIsSafe: bool,
     * }
     */
    public static function data(TenderMatch $match): array
    {
        $tender = $match->tender;
        $source = $tender->source;

        $rawUrl = (string) $tender->source_url;
        $urlScheme = strtolower((string) parse_url($rawUrl, PHP_URL_SCHEME));

        return [
            'title' => (string) $tender->title,
            'price' => $tender->price === null
                ? 'не указана'
                : number_format((float) $tender->price, 2, ',', ' ').' ₽',
            'region' => $tender->region !== null && $tender->region !== ''
                ? (string) $tender->region
                : 'не указан',
            'deadline' => $tender->deadline_at?->format('d.m.Y H:i') ?? 'не указан',
            'source' => $source !== null ? (string) $source->name : 'не указан',
            'level' => $match->level->label(),
            'score' => (int) $match->score,
            'recommendation' => $match->action_recommendation->label(),
            'reasons' => MatchReasonPresenter::lines($match->reason),
            'risks' => MatchReasonPresenter::risks($match->risk_flags),
            'url' => $rawUrl,
            'urlIsSafe' => in_array($urlScheme, ['http', 'https'], true),
        ];
    }
}
