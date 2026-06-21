<?php

namespace App\Console\Commands;

use App\Notifications\MaxGateway;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Страж протухания токена ЕИС. Токен getDocsIP выпускается через КЭП и годен
 * ограниченно (текущий — до декабря 2026); молчаливое протухание убьёт пайплайн.
 * Команда (на ежедневном расписании) заранее шлёт операционный MAX-алерт в
 * ops-чат, когда до срока осталось <= порога, или если токен уже протёк.
 */
class EisTokenCheckCommand extends Command
{
    protected $signature = 'eis:token-check {--days= : Порог в днях (по умолчанию config eis.token_alert_days)}';

    protected $description = 'Проверить срок годности токена ЕИС и заранее предупредить о протухании';

    public function handle(MaxGateway $max): int
    {
        $expiresRaw = config('eis.token_expires_at');
        if (! is_string($expiresRaw) || $expiresRaw === '') {
            $this->warn('EIS_TOKEN_EXPIRES_AT не задан — проверка пропущена.');

            return self::SUCCESS;
        }

        // Строгий парс: createFromFormat('!Y-m-d') отвергает не только синтаксический
        // мусор (бросает InvalidFormatException), но и семантически невалидные даты
        // (2026-02-30 — getLastErrors warning_count>0), которые parse() молча перенёс
        // бы на след. месяц и сдвинул бы расчёт срока.
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $expiresRaw);
        } catch (Throwable) {
            $parsed = false;
        }
        $parseErrors = CarbonImmutable::getLastErrors();
        if (! $parsed instanceof CarbonImmutable
            || (is_array($parseErrors) && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
        ) {
            $this->error("Не удалось разобрать EIS_TOKEN_EXPIRES_AT='{$expiresRaw}' (ожидается YYYY-MM-DD).");

            return self::SUCCESS;
        }
        $expiresAt = $parsed->endOfDay();

        $daysOpt = $this->option('days');
        $threshold = is_scalar($daysOpt) && (string) $daysOpt !== ''
            ? (int) $daysOpt
            : (int) config('eis.token_alert_days', 30);

        $now = CarbonImmutable::now();
        $daysLeft = (int) floor(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);

        if ($daysLeft > $threshold) {
            $this->info("Токен ЕИС годен ещё {$daysLeft} дн. (до {$expiresAt->toDateString()}). Алерт не нужен.");

            return self::SUCCESS;
        }

        $text = $daysLeft < 0
            ? "⛔ Токен ЕИС ПРОТЁК {$expiresAt->toDateString()} (".abs($daysLeft).' дн. назад). Пайплайн ЕИС не работает — выпусти новый токен через КЭП.'
            : "⚠️ Токен ЕИС протухает через {$daysLeft} дн. (до {$expiresAt->toDateString()}). Запланируй перевыпуск через КЭП.";

        Log::warning('[eis:token-check] '.$text);
        $this->warn($text);

        $opsChat = config('eis.ops_max_chat_id');
        if (is_string($opsChat) && $opsChat !== '') {
            try {
                $max->sendMessage($opsChat, $text);
                $this->info("Алерт отправлен в MAX ops-чат {$opsChat}.");
            } catch (Throwable $e) {
                // Алерт о протухании не должен сам валить команду — логируем и выходим успешно.
                Log::error('[eis:token-check] не удалось отправить MAX-алерт: '.$e->getMessage());
                $this->error('Не удалось отправить MAX-алерт: '.$e->getMessage());
            }
        } else {
            $this->warn('eis.ops_max_chat_id не задан — алерт только в лог.');
        }

        return self::SUCCESS;
    }
}
