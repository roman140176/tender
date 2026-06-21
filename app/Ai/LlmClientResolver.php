<?php

namespace App\Ai;

use App\Ai\Contracts\LlmClient;
use App\Ai\Providers\NullLlmClient;
use App\Ai\Providers\QwenLlmClient;
use InvalidArgumentException;

/**
 * Фабрика: тир (cheap/strong) → LlmClient. Зеркалит ChannelNotifierResolver.
 * При выключенном ai.enabled всегда отдаёт NullLlmClient (safe-дефолт). Модель
 * берётся из конфига тира и передаётся провайдеру — так один провайдер
 * обслуживает оба тира разными моделями.
 */
final class LlmClientResolver
{
    public function for(string $tier): LlmClient
    {
        if (! (bool) config('ai.enabled', false)) {
            return new NullLlmClient;
        }

        $tierCfg = config("ai.tiers.{$tier}");
        if (! is_array($tierCfg)) {
            throw new InvalidArgumentException("Неизвестный AI-тир: {$tier}");
        }

        $providerName = (string) ($tierCfg['provider'] ?? '');
        $model = (string) ($tierCfg['model'] ?? '');

        $providerCfg = config("ai.providers.{$providerName}");
        if (! is_array($providerCfg)) {
            throw new InvalidArgumentException("Неизвестный AI-провайдер: {$providerName}");
        }

        return match ($providerName) {
            'qwen' => new QwenLlmClient(
                apiKey: (string) ($providerCfg['key'] ?? ''),
                baseUrl: (string) ($providerCfg['base'] ?? ''),
                model: $model,
                timeout: (int) ($providerCfg['timeout'] ?? 60),
            ),
            default => throw new InvalidArgumentException("Провайдер {$providerName} не реализован."),
        };
    }

    public function default(): LlmClient
    {
        return $this->for((string) config('ai.default_tier', 'cheap'));
    }
}
