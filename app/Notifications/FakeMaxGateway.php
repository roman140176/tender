<?php

namespace App\Notifications;

/**
 * In-memory реализация MaxGateway для тестов (паттерн EisGateway-fake).
 * Сваппится через $this->app->instance(MaxGateway::class, $fake).
 * Накапливает отправленные сообщения в публичном массиве для ассертов.
 */
class FakeMaxGateway implements MaxGateway
{
    /** @var list<array{chatId: string, text: string, opts: array<string, mixed>}> */
    public array $sent = [];

    /** Если задано — sendMessage бросит это исключение (для теста транспорт-ошибки в NotifyJob). */
    public ?\Throwable $throwOnSend = null;

    public function sendMessage(string $chatId, string $text, array $opts = []): void
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        $this->sent[] = [
            'chatId' => $chatId,
            'text' => $text,
            'opts' => $opts,
        ];
    }

    /** @return list<array{chatId: string, text: string, opts: array<string, mixed>}> */
    public function messagesTo(string $chatId): array
    {
        return array_values(array_filter($this->sent, fn (array $m) => $m['chatId'] === $chatId));
    }
}
