<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;

/**
 * Тест-дубль LLM (как FakeMaxGateway для MAX): отдаёт заранее заданный ответ и
 * копит все запросы в $calls для проверок. Без сети — для unit-тестов сервисов,
 * которые зависят от LlmClient (TenderSummarizer и т.п.).
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<LlmRequest> */
    public array $calls = [];

    public function __construct(private readonly LlmResponse $response) {}

    public static function returning(
        string $content,
        int $prompt = 1,
        int $completion = 1,
        string $model = 'fake',
        string $provider = 'fake',
    ): self {
        return new self(new LlmResponse($content, $prompt, $completion, $model, $provider));
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->calls[] = $request;

        return $this->response;
    }
}
