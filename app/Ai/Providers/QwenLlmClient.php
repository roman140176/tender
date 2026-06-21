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
 * Драйвер Qwen (Alibaba DashScope) через OpenAI-совместимый эндпоинт
 * POST {base}/chat/completions с Bearer-ключом. Модель фиксируется при
 * создании клиента — резолвер подставляет её из конфига тира (cheap/strong),
 * поэтому один провайдер обслуживает оба тира разными моделями.
 */
final class QwenLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout = 60,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        if ($this->apiKey === '') {
            throw new AiDisabledException('QWEN_API_KEY не задан — провайдер qwen недоступен.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
        ];
        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }
        if ($request->jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload)
                ->throw();
        } catch (Throwable $e) {
            // Любой сбой HTTP-вызова (RequestException на 4xx/5xx, ConnectionException
            // на таймауте/обрыве ГОСТ-TLS/DNS) → единый транспортный класс.
            throw new LlmTransportException('Qwen: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();
        $usage = (array) ($data['usage'] ?? []);

        return new LlmResponse(
            content: (string) (data_get($data, 'choices.0.message.content') ?? ''),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: (string) ($data['model'] ?? $this->model),
            provider: 'qwen',
        );
    }
}
