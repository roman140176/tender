<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\AiDisabledException;
use App\Ai\Exceptions\LlmTransportException;
use App\Ai\LlmClientResolver;
use App\Ai\LlmRequest;
use Illuminate\Console\Command;

/**
 * Живой смоук-тест AI-слоя — ОДИН реальный вызов LLM-провайдера через настоящий
 * резолвер и драйвер (аналог eis:probe для ЕИС). Доказывает, что транспорт +
 * авторизация + ключ работают и провайдер реально отвечает, а не «гасится».
 *
 * Выхлоп различает классы отказа:
 *   AiDisabledException   → AI выключен (AI_ENABLED) или не задан ключ провайдера
 *   LlmTransportException → сеть / HTTP 4xx-5xx (ключ, folder, недоступный параметр)
 *   успех                → печатает ответ, токены, провайдера и модель
 */
class AiProbeCommand extends Command
{
    protected $signature = 'ai:probe
        {--tier=cheap : Тир: cheap (массовое) или strong (премиум)}
        {--prompt= : Свой пользовательский промпт вместо дефолтного}
        {--json : Запросить JSON-режим (response_format) — проверка json-пути}';

    protected $description = 'Живой смоук-тест AI: один реальный вызов LLM через резолвер/драйвер';

    public function handle(LlmClientResolver $resolver): int
    {
        $tier = (string) $this->option('tier');
        $providerCfg = (array) config("ai.tiers.{$tier}", []);
        $provider = (string) ($providerCfg['provider'] ?? '?');
        $model = (string) ($providerCfg['model'] ?? '?');

        $this->line('AI включён: <info>'.(config('ai.enabled') ? 'да' : 'НЕТ (AI_ENABLED)').'</info>');
        $this->line("Тир:        <info>{$tier}</info> → провайдер <info>{$provider}</info>, модель <info>{$model}</info>");

        $system = 'Ты — аналитик госзакупок. Отвечай кратко и по-русски.';
        $user = is_string($this->option('prompt')) && $this->option('prompt') !== ''
            ? (string) $this->option('prompt')
            : 'Одним предложением: зачем поставщику мониторить тендеры?';

        $request = new LlmRequest(
            system: $system,
            user: $user,
            jsonMode: (bool) $this->option('json'),
            maxTokens: 256,
        );

        try {
            $t0 = microtime(true);
            $response = $resolver->for($tier)->complete($request);
            $ms = (int) ((microtime(true) - $t0) * 1000);
        } catch (AiDisabledException $e) {
            $this->newLine();
            $this->error('❌ AI недоступен: '.$e->getMessage());
            $this->line('   → Включи AI_ENABLED=true и задай ключ провайдера в .env');
            $this->line('     (для YandexGPT: AI_CHEAP_PROVIDER=yandexgpt, YANDEX_API_KEY, YANDEX_FOLDER_ID).');

            return self::FAILURE;
        } catch (LlmTransportException $e) {
            $this->newLine();
            $this->error('❌ ТРАНСПОРТ/HTTP: провайдер не ответил успехом — НЕ генерация, а связь/доступ.');
            $this->line('   '.$e->getMessage());
            $this->line('   → Проверь ключ/folder, доступность хоста и поддержку параметров (например, response_format).');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('❌ '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✅ Ответ за {$ms}мс — провайдер <comment>{$response->provider}</comment>, модель <comment>{$response->model}</comment>. СВЯЗЬ РАБОТАЕТ. 🎉");
        $this->line('   Токены: prompt='.$response->promptTokens.', completion='.$response->completionTokens.', всего='.$response->totalTokens());
        $this->newLine();
        $this->line($response->content);

        return self::SUCCESS;
    }
}
