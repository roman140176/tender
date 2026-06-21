<?php

namespace App\Ai\Contracts;

use App\Ai\Exceptions\AiDisabledException;
use App\Ai\Exceptions\LlmTransportException;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;

/**
 * Абстракция LLM-провайдера (Qwen/GigaChat/...). Скрывает транспорт и формат
 * конкретного вендора. Сервисы (TenderSummarizer и т.п.) работают только с этим
 * интерфейсом; конкретный клиент выбирается по тиру через LlmClientResolver —
 * ровно как ChannelNotifier/ChannelNotifierResolver для уведомлений.
 */
interface LlmClient
{
    /**
     * @throws AiDisabledException если AI не сконфигурирован
     * @throws LlmTransportException при сетевой/HTTP-ошибке провайдера
     */
    public function complete(LlmRequest $request): LlmResponse;
}
