<?php

namespace App\Notifications;

use Illuminate\Http\Client\RequestException;

interface MaxGateway
{
    /**
     * Отправить сообщение в MAX-чат.
     *
     * @param  string  $chatId  целевой chat_id (companies.max_chat_id)
     * @param  string  $text  готовый текст (HTML-разметка; format:'html' выставляется транспортом)
     * @param  array<string, mixed>  $opts  доп. параметры MAX Bot API (notify, ...)
     *
     * @throws RequestException при HTTP-ошибке транспорта
     */
    public function sendMessage(string $chatId, string $text, array $opts = []): void;
}
