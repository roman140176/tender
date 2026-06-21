<?php

return [

    // Глобальный рубильник. Без него (по умолчанию) резолвер отдаёт NullLlmClient —
    // прод/миграции не падают из-за отсутствия ключа. Включается явно через env.
    'enabled' => (bool) env('AI_ENABLED', false),

    // Тир по умолчанию для сервисов, которым не важна «сила» модели (саммари/скоринг).
    'default_tier' => env('AI_DEFAULT_TIER', 'cheap'),

    // Тиринг по задаче: cheap — массовое (саммари/скоринг), strong — премиум (КП).
    // Провайдер и модель — строчка в конфиге, без переписывания кода.
    'tiers' => [
        'cheap' => [
            'provider' => env('AI_CHEAP_PROVIDER', 'qwen'),
            'model' => env('AI_CHEAP_MODEL', 'qwen-turbo'),
        ],
        'strong' => [
            'provider' => env('AI_STRONG_PROVIDER', 'qwen'),
            'model' => env('AI_STRONG_MODEL', 'qwen-max'),
        ],
    ],

    // Конкретные провайдеры. Qwen — первый драйвер (OpenAI-совместимый).
    // RU-native (gigachat/yandexgpt) добавляются сюда же за тем же интерфейсом.
    'providers' => [
        'qwen' => [
            'key' => env('QWEN_API_KEY'),
            'base' => env('QWEN_API_BASE', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'),
            'timeout' => (int) env('AI_TIMEOUT', 60),
        ],
    ],

    // Месячный лимит токенов на компанию (0 = безлимит). Enforcement — follow-up.
    'monthly_token_limit' => (int) env('AI_MONTHLY_TOKEN_LIMIT', 0),
];
