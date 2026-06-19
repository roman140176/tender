<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Боевой транспорт MAX Bot API через Http-фасад (паттерн SoapEisGateway).
 *
 * POST {api_base}/messages?chat_id={chatId}, заголовок Authorization: {token},
 * тело JSON {text, format:'html'}. $response->throw() конвертирует HTTP 4xx/5xx
 * в RequestException, чтобы провижиненный ретрай NotifyJob (tries=3) отработал.
 *
 * Токен передаётся только в заголовке Authorization — НЕ в URL → не попадает
 * в текст RequestException и не дублируется в собственных сообщениях об ошибках.
 * Заголовок не логируется приложением явно (§0 спеки, §7 прод-долг).
 */
class HttpMaxGateway implements MaxGateway
{
    public function sendMessage(string $chatId, string $text, array $opts = []): void
    {
        $token = (string) config('services.max.bot_token');

        // config() возвращает явный null (а не дефолт), если ключ задан null —
        // поэтому отдельно подстраховываемся ?: на пустое значение.
        $apiBase = (string) config('services.max.api_base', 'https://platform-api.max.ru');
        $apiBase = rtrim($apiBase !== '' ? $apiBase : 'https://platform-api.max.ru', '/');

        /** @var array<string, mixed> $payload */
        $payload = array_merge(
            ['text' => $text, 'format' => 'html'],
            $opts,
        );

        Http::withHeaders(['Authorization' => $token])
            ->asJson()
            ->post($apiBase.'/messages?chat_id='.rawurlencode($chatId), $payload)
            ->throw();
    }
}
