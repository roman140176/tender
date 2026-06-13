<?php

namespace App\Scoring;

final class MatchReasonPresenter
{
    /** rule => шаблон. {detail} подставляется, знак балла добавляется отдельно. */
    private const RULE_LABELS = [
        'exact_in_title' => 'Точная фраза в названии: «{detail}»',
        'exact_in_description' => 'Точная фраза в описании: «{detail}»',
        'keyword_in_title' => 'Ключ в названии: «{detail}»',
        'keyword_in_description' => 'Ключ в описании: «{detail}»',
        'high_priority_bonus' => 'Высокоприоритетный ключ: «{detail}»',
        'low_priority_score' => 'Низкоприоритетный ключ: «{detail}»',
        'negative_keyword' => 'Минус-слово: «{detail}»',
        'price_above_min' => 'Цена не ниже минимума: {detail}',
        'price_below_min' => 'Цена ниже минимума: {detail}',
        'priority_region' => 'Приоритетный регион: {detail}',
        'deadline_far_bonus' => 'Достаточный срок до дедлайна: {detail}',
        'deadline_soon_penalty' => 'Слишком близкий дедлайн: {detail}',
        'okpd_match' => 'Совпадение по ОКПД2/КТРУ: {detail}',
        'source_closed' => 'Закупка закрыта/отменена',
    ];

    private const RISK_LABELS = [
        'deadline_soon' => 'Скоро дедлайн',
        'no_price' => 'Цена не указана',
        'no_deadline' => 'Дедлайн не указан',
        'source_closed' => 'Источник закрыт',
    ];

    /**
     * @param  list<array{rule?: string, points?: int, detail?: string}>  $reason
     * @return list<string>
     */
    public static function lines(array $reason): array
    {
        $out = [];
        foreach ($reason as $entry) {
            $rule = (string) ($entry['rule'] ?? '');
            $points = (int) ($entry['points'] ?? 0);
            $detail = (string) ($entry['detail'] ?? '');

            $template = self::RULE_LABELS[$rule] ?? "{$rule}: {detail}";
            // str_replace не рекурсивен: {detail} внутри самого $detail не интерпретируется (экранирование не нужно).
            $text = str_replace('{detail}', $detail, $template);

            $out[] = $text.' ('.self::sign($points).')';
        }

        return $out;
    }

    /**
     * @param  list<string>  $flags
     * @return list<string>
     */
    public static function risks(array $flags): array
    {
        return array_map(
            fn (string $flag): string => self::RISK_LABELS[$flag] ?? $flag,
            $flags,
        );
    }

    private static function sign(int $points): string
    {
        if ($points === 0) {
            return '0';
        }

        return $points < 0 ? '−'.abs($points) : '+'.$points;
    }
}
