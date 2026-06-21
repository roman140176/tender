<?php

namespace App\Ai;

/**
 * Ответ LLM, независимый от вендора. Токены разбиты на prompt/completion для
 * точного учёта стоимости (AiUsage); totalTokens() — то, что обычно пишут в БД.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
        public string $provider,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
