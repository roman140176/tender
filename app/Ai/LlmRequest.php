<?php

namespace App\Ai;

/**
 * Запрос к LLM, независимый от вендора. system+user разделены намеренно:
 * провайдер сам решает, как их уложить (OpenAI-messages, отдельные поля и т.п.).
 * jsonMode просит провайдера вернуть строгий JSON (для структурного разбора).
 */
final readonly class LlmRequest
{
    public function __construct(
        public string $system,
        public string $user,
        public bool $jsonMode = false,
        public ?int $maxTokens = null,
    ) {}
}
