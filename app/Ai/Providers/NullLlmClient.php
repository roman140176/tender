<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\LlmClient;
use App\Ai\Exceptions\AiDisabledException;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;

/**
 * Safe-дефолт: AI не сконфигурирован (нет ключа/провайдера). Любой вызов бросает
 * AiDisabledException, чтобы прод/миграции не падали из-за отсутствия ключа, а
 * вызывающий код мог чётко поймать «AI выключен» и деградировать штатно.
 */
final class NullLlmClient implements LlmClient
{
    public function complete(LlmRequest $request): LlmResponse
    {
        throw new AiDisabledException;
    }
}
