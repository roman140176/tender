<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\TenderSourceStatus;
use App\Models\NotificationLog;
use App\Models\TenderMatch;

/**
 * Антидубль уведомлений (§9 спеки). Решает, слать ли уведомление по паре
 * (профиль × закупка). Первый раз — слать. Повтор — только при явном ре-триггере:
 * цена ±≥10%, изменился дедлайн, source_status стал терминальным, score пересёк
 * notification_threshold снизу вверх. Сравнение — со снимком payload прошлого
 * sent-уведомления (пишет NotifyJob). Антидубль — per-канал: история MAX и Email
 * независимы (sent-лог MAX не глушит Email и наоборот).
 */
final class NotificationDecider
{
    private const PRICE_DELTA = 0.10;

    /** @var list<TenderSourceStatus> */
    private const TERMINAL = [TenderSourceStatus::Completed, TenderSourceStatus::Cancelled];

    public function shouldNotify(TenderMatch $match, NotificationChannel $channel): bool
    {
        // company_id избыточен (search_profile_id глобально уникален одной компании),
        // но явный предикат надёжнее/аудируемее и совпадает с паттерном фичи —
        // лог другой компании по тем же profile+tender не должен влиять на решение.
        $last = NotificationLog::withoutTenancy()
            ->where('company_id', $match->company_id)
            ->where('search_profile_id', $match->search_profile_id)
            ->where('tender_id', $match->tender_id)
            ->where('channel', $channel)
            ->where('status', NotificationStatus::Sent)
            ->latest('id')
            ->first();

        if ($last === null) {
            return true;
        }

        /** @var array<string, mixed> $payload */
        $payload = $last->payload;
        $tender = $match->tender;

        return $this->priceChanged($tender->price, $payload['price'] ?? null)
            || $this->deadlineChanged($tender->deadline_at?->toIso8601String(), $payload['deadline_at'] ?? null)
            || $this->becameTerminal($tender->source_status, $payload['source_status'] ?? null)
            || $this->crossedThresholdUp($match, $payload['score'] ?? null);
    }

    private function priceChanged(?string $current, mixed $previous): bool
    {
        // Сознательная консервативность: если цена была известна, а стала null
        // (источник временно вернул пусто), НЕ считаем это ре-триггером — чтобы
        // не плодить шум на транзиентных null'ах источника. (Ревью одобрило.)
        if ($current === null) {
            return false;
        }
        $new = (float) $current;
        $old = $previous === null ? 0.0 : (float) $previous;

        if ($old <= 0.0) {
            // прошлой цены не было (0/null), а сейчас задана — считаем изменением
            return $new > 0.0;
        }

        return abs($new - $old) / $old >= self::PRICE_DELTA;
    }

    private function deadlineChanged(?string $current, mixed $previous): bool
    {
        $prev = $previous === null ? null : (string) $previous;

        return $current !== $prev;
    }

    private function becameTerminal(TenderSourceStatus $current, mixed $previous): bool
    {
        $isTerminalNow = in_array($current, self::TERMINAL, true);
        if (! $isTerminalNow) {
            return false;
        }

        $prev = $previous === null ? null : TenderSourceStatus::tryFrom((string) $previous);
        $wasTerminal = $prev !== null && in_array($prev, self::TERMINAL, true);

        // триггер только при переходе нетерминальный → терминальный
        return ! $wasTerminal;
    }

    private function crossedThresholdUp(TenderMatch $match, mixed $previousScore): bool
    {
        $threshold = (int) $match->searchProfile->notification_threshold;
        $old = $previousScore === null ? 0 : (int) $previousScore;

        return $old < $threshold && $match->score >= $threshold;
    }
}
