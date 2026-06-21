<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\LlmClient;
use App\Ai\Exceptions\AiDisabledException;
use App\Ai\Exceptions\LlmTransportException;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Драйвер YandexGPT (Yandex Cloud Foundation Models) через OpenAI-совместимый
 * эндпоинт POST {base}/chat/completions. Отличия от Qwen: авторизация заголовком
 * `Authorization: Api-Key <ключ>` (не Bearer), а поле `model` — это modelUri вида
 * `gpt://<folder>/<model>` (короткое имя модели из конфига тира оборачивается
 * каталогом при создании запроса). Один провайдер обслуживает оба тира разными
 * моделями (yandexgpt-lite / yandexgpt).
 */
final class YandexGptLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $folder,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 60,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === '') {
            throw new AiDisabledException('YANDEX_API_KEY не задан — провайдер yandexgpt недоступен.');
        }
        if ($this->folder === '' && ! str_starts_with($this->model, 'gpt://')) {
            // Без каталога не собрать корректный modelUri gpt://<folder>/<model>.
            throw new AiDisabledException('YANDEX_FOLDER_ID не задан — провайдер yandexgpt недоступен.');
        }

        $payload = [
            'model' => $this->modelUri(),
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
        ];
        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }
        if ($request->jsonMode) {
            // ✅ VERIFIED живьём (`ai:probe --json`, 2026-06-21): Yandex OpenAI-compat
            // соблюдает OpenAI-поле response_format — вернул валидный JSON-объект
            // (не 400, не деградация в прозу). Безопасно для TenderSummarizer.
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // Каталог дублируем в x-folder-id (рабочий пример Yandex шлёт его рядом с
        // modelUri): при Api-Key-авторизации эндпоинт может требовать явный каталог
        // для маршрутизации; при готовом gpt:// URI (folder пуст) — не шлём.
        $headers = ['Authorization' => 'Api-Key '.$this->apiKey];
        if ($this->folder !== '') {
            $headers['x-folder-id'] = $this->folder;
        }

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload)
                ->throw();
        } catch (Throwable $e) {
            // Любой сбой HTTP-вызова (RequestException на 4xx/5xx, ConnectionException
            // на таймауте/обрыве TLS/DNS) → единый транспортный класс.
            throw new LlmTransportException('YandexGPT: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();
        $usage = (array) ($data['usage'] ?? []);

        return new LlmResponse(
            content: (string) (data_get($data, 'choices.0.message.content') ?? ''),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: (string) ($data['model'] ?? $this->model),
            provider: 'yandexgpt',
        );
    }

    /**
     * Оборачивает короткое имя модели каталогом: `yandexgpt-lite` →
     * `gpt://<folder>/yandexgpt-lite`. Уже готовый `gpt://...` пропускает как есть
     * (позволяет пинить версию: `gpt://<folder>/yandexgpt/rc`).
     */
    private function modelUri(): string
    {
        return str_starts_with($this->model, 'gpt://')
            ? $this->model
            : "gpt://{$this->folder}/{$this->model}";
    }
}
