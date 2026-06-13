<?php

namespace App\Scoring;

use App\Enums\ActionRecommendation;
use App\Enums\KeywordType;
use App\Enums\RelevanceLevel;
use App\Enums\TenderSourceStatus;
use App\Models\SearchProfile;
use App\Models\Tender;

final class Scorer
{
    /**
     * Детерминированный скоринг пары (закупка × профиль) по §7.2.
     * Принимает уже разрешённые попадания ключей (где сработал каждый) — морфология/поиск вне Scorer.
     *
     * @param  list<KeywordHit>  $positiveHits  попадания положительных ключей (main/exact/broad/high/low)
     * @param  list<KeywordHit>  $negativeHits  попадания негативных ключей
     */
    public function score(
        Tender $tender,
        SearchProfile $profile,
        ScoringWeights $weights,
        array $positiveHits,
        array $negativeHits,
    ): ScoreResult {
        $matchedNegatives = array_values(array_filter($negativeHits, fn (KeywordHit $h) => $h->matched()));

        // Раннее исключение: негатив в названии при включённом флаге — матч не создаётся (§7.2 п.5).
        $negativeInTitle = $this->anyInField($matchedNegatives, 'title');
        if ($negativeInTitle && $profile->exclude_on_negative_in_title) {
            return new ScoreResult(
                score: 0,
                level: RelevanceLevel::Low,
                recommendation: ActionRecommendation::Decline,
                reason: [],
                matchedKeywords: [],
                matchedNegativeKeywords: $this->toPayload($matchedNegatives),
                riskFlags: [],
                excluded: true,
            );
        }

        $matchedPositives = array_values(array_filter($positiveHits, fn (KeywordHit $h) => $h->matched()));

        $score = 0;
        $reason = [];
        $riskFlags = [];

        $add = function (int $points, string $rule, string $detail) use (&$score, &$reason): void {
            $score += $points;
            $reason[] = ['rule' => $rule, 'points' => $points, 'detail' => $detail];
        };

        // --- Текстовые правила (§7.2 п.1-5) ---
        $exactHits = $this->byType($matchedPositives, KeywordType::Exact);
        $mainBroadHits = $this->byTypes($matchedPositives, [KeywordType::Main, KeywordType::Broad]);
        $highHits = $this->byType($matchedPositives, KeywordType::HighPriority);
        $lowHits = $this->byType($matchedPositives, KeywordType::LowPriority);

        // Правило 1: exact
        if ($this->anyInField($exactHits, 'title')) {
            $add($weights->get('exact_in_title'), 'exact_in_title', $this->firstValue($exactHits, 'title'));
        } elseif ($this->anyInField($exactHits, 'description')) {
            $add($weights->get('exact_in_description'), 'exact_in_description', $this->firstValue($exactHits, 'description'));
        }

        // Правило 2: main/broad (максимум по полю)
        if ($this->anyInField($mainBroadHits, 'title')) {
            $add($weights->get('keyword_in_title'), 'keyword_in_title', $this->firstValue($mainBroadHits, 'title'));
        } elseif ($this->anyInField($mainBroadHits, 'description')) {
            $add($weights->get('keyword_in_description'), 'keyword_in_description', $this->firstValue($mainBroadHits, 'description'));
        }

        // Правило 3: high_priority bonus (+10 за наличие, не даёт базу сам по себе)
        if ($highHits !== []) {
            $add($weights->get('high_priority_bonus'), 'high_priority_bonus', $highHits[0]->value);
        }

        // Правило 4: low_priority +2 только при отсутствии main/exact/broad
        if ($lowHits !== [] && $exactHits === [] && $mainBroadHits === []) {
            $add($weights->get('low_priority_score'), 'low_priority_score', $lowHits[0]->value);
        }

        // Правило 5: негатив −20 однократно (исключение уже отработано выше)
        if ($matchedNegatives !== []) {
            $add($weights->get('negative_keyword'), 'negative_keyword', $matchedNegatives[0]->value);
        }

        // --- Прочие правила (§7.2) ---
        // Цена
        $price = $tender->price;            // string|null (decimal:2)
        $minPrice = $profile->min_price;    // string|null (decimal:2)
        if ($price === null) {
            $riskFlags[] = 'no_price';
        }
        if ($price !== null && $minPrice !== null) {
            if (bccomp($price, $minPrice, 2) >= 0) {
                $add($weights->get('price_above_min'), 'price_above_min', "{$price} ≥ {$minPrice}");
            } else {
                $add($weights->get('price_below_min'), 'price_below_min', "{$price} < {$minPrice}");
            }
        }

        // Приоритетный регион
        $regions = $profile->regions ?? [];
        if ($tender->region !== null && in_array($tender->region, $regions, true)) {
            $add($weights->get('priority_region'), 'priority_region', $tender->region);
        }

        // Дедлайн
        $deadline = $tender->deadline_at;
        if ($deadline === null) {
            $riskFlags[] = 'no_deadline';
        } else {
            $now = now();
            if ($deadline->greaterThan($now->copy()->addDays($weights->get('deadline_far_days')))) {
                $add($weights->get('deadline_far_bonus'), 'deadline_far_bonus', $deadline->toDateString());
            } elseif ($deadline->lessThan($now->copy()->addDays($weights->get('deadline_soon_days')))) {
                $add($weights->get('deadline_soon_penalty'), 'deadline_soon_penalty', $deadline->toDateString());
                $riskFlags[] = 'deadline_soon';
            }
        }

        // ОКПД2/КТРУ префиксное совпадение
        $profileCodes = array_filter($profile->okpd2_codes ?? [], fn ($c) => (string) $c !== '');
        if ($profileCodes !== []) {
            $tenderCodes = array_merge($tender->okpd2 ?? [], $tender->ktru ?? []);
            foreach ($tenderCodes as $tc) {
                foreach ($profileCodes as $pc) {
                    if (str_starts_with((string) $tc, (string) $pc)) {
                        $add($weights->get('okpd_match'), 'okpd_match', (string) $tc);
                        break 2;
                    }
                }
            }
        }

        // Статус источника
        if (in_array($tender->source_status, [TenderSourceStatus::Completed, TenderSourceStatus::Cancelled], true)) {
            $add($weights->get('source_closed'), 'source_closed', $tender->source_status->value);
            $riskFlags[] = 'source_closed';
        }

        // --- Итог ---
        $score = max(0, min(100, $score));
        $level = RelevanceLevel::fromScore($score);

        return new ScoreResult(
            score: $score,
            level: $level,
            recommendation: $this->recommendation($level),
            reason: $reason,
            matchedKeywords: $this->toPayload($matchedPositives),
            matchedNegativeKeywords: $this->toPayload($matchedNegatives),
            riskFlags: $riskFlags,
            excluded: false,
        );
    }

    private function recommendation(RelevanceLevel $level): ActionRecommendation
    {
        return match ($level) {
            RelevanceLevel::High => ActionRecommendation::HandOver,
            RelevanceLevel::Medium => ActionRecommendation::ReviewDocs,
            RelevanceLevel::Weak => ActionRecommendation::Monitor,
            RelevanceLevel::Low => ActionRecommendation::Decline,
        };
    }

    /**
     * @param  list<KeywordHit>  $hits
     * @return list<KeywordHit>
     */
    private function byType(array $hits, KeywordType $type): array
    {
        return array_values(array_filter($hits, fn (KeywordHit $h) => $h->type === $type));
    }

    /**
     * @param  list<KeywordHit>  $hits
     * @param  list<KeywordType>  $types
     * @return list<KeywordHit>
     */
    private function byTypes(array $hits, array $types): array
    {
        return array_values(array_filter($hits, fn (KeywordHit $h) => in_array($h->type, $types, true)));
    }

    /** @param  list<KeywordHit>  $hits */
    private function anyInField(array $hits, string $field): bool
    {
        foreach ($hits as $h) {
            if ($h->field === $field) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<KeywordHit>  $hits */
    private function firstValue(array $hits, string $field): string
    {
        foreach ($hits as $h) {
            if ($h->field === $field) {
                return $h->value;
            }
        }

        return '';
    }

    /**
     * @param  list<KeywordHit>  $hits
     * @return list<array{value: string, type: string, field: string}>
     */
    private function toPayload(array $hits): array
    {
        return array_map(fn (KeywordHit $h) => [
            'value' => $h->value,
            'type' => $h->type->value,
            'field' => (string) $h->field,
        ], $hits);
    }
}
