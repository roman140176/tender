<?php

use App\Ai\Exceptions\AiDisabledException;
use App\Ai\Exceptions\LlmTransportException;
use App\Ai\LlmRequest;
use App\Ai\Providers\QwenLlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function qwenClient(string $key = 'sk-test', string $model = 'qwen-turbo'): QwenLlmClient
{
    return new QwenLlmClient(
        apiKey: $key,
        baseUrl: 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
        model: $model,
    );
}

it('шлёт корректный OpenAI-совместимый запрос и парсит ответ', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'qwen-turbo',
            'choices' => [['message' => ['content' => '{"summary":"ok"}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 25],
        ]),
    ]);

    $res = qwenClient()->complete(new LlmRequest(system: 'СИС', user: 'ЮЗЕР', jsonMode: true, maxTokens: 256));

    expect($res->content)->toBe('{"summary":"ok"}')
        ->and($res->promptTokens)->toBe(100)
        ->and($res->completionTokens)->toBe(25)
        ->and($res->model)->toBe('qwen-turbo');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_ends_with($request->url(), '/chat/completions')
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $body['model'] === 'qwen-turbo'
            && $body['messages'][0] === ['role' => 'system', 'content' => 'СИС']
            && $body['messages'][1] === ['role' => 'user', 'content' => 'ЮЗЕР']
            && $body['max_tokens'] === 256
            && $body['response_format'] === ['type' => 'json_object'];
    });
});

it('маппит HTTP-ошибку провайдера в LlmTransportException', function () {
    Http::fake(['*/chat/completions' => Http::response('upstream boom', 500)]);

    qwenClient()->complete(new LlmRequest(system: 's', user: 'u'));
})->throws(LlmTransportException::class);

it('пустой ключ → AiDisabledException (без сетевого вызова)', function () {
    Http::fake();

    qwenClient(key: '')->complete(new LlmRequest(system: 's', user: 'u'));
})->throws(AiDisabledException::class);

it('200 без choices → пустой content, но usage учтён (без исключения)', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'qwen-turbo',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 0],
        ]),
    ]);

    $res = qwenClient()->complete(new LlmRequest(system: 's', user: 'u'));

    expect($res->content)->toBe('')
        ->and($res->promptTokens)->toBe(5);
});

it('ConnectionException (таймаут/обрыв ГОСТ-TLS) → LlmTransportException', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    qwenClient()->complete(new LlmRequest(system: 's', user: 'u'));
})->throws(LlmTransportException::class);
