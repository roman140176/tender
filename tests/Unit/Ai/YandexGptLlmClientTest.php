<?php

use App\Ai\Exceptions\AiDisabledException;
use App\Ai\Exceptions\LlmTransportException;
use App\Ai\LlmRequest;
use App\Ai\Providers\YandexGptLlmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function yandexClient(string $key = 'ya-test', string $folder = 'b1gfolder', string $model = 'yandexgpt-lite'): YandexGptLlmClient
{
    return new YandexGptLlmClient(
        apiKey: $key,
        folder: $folder,
        baseUrl: 'https://llm.api.cloud.yandex.net/v1',
        model: $model,
    );
}

it('шлёт OpenAI-совместимый запрос с Api-Key и modelUri gpt://folder/model, парсит ответ', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'yandexgpt-lite',
            'choices' => [['message' => ['content' => '{"summary":"ok"}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 25],
        ]),
    ]);

    $res = yandexClient()->complete(new LlmRequest(system: 'СИС', user: 'ЮЗЕР', jsonMode: true, maxTokens: 256));

    expect($res->content)->toBe('{"summary":"ok"}')
        ->and($res->promptTokens)->toBe(100)
        ->and($res->completionTokens)->toBe(25)
        ->and($res->provider)->toBe('yandexgpt');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_ends_with($request->url(), '/chat/completions')
            && $request->hasHeader('Authorization', 'Api-Key ya-test')
            && $request->hasHeader('x-folder-id', 'b1gfolder')
            && $body['model'] === 'gpt://b1gfolder/yandexgpt-lite'
            && $body['messages'][0] === ['role' => 'system', 'content' => 'СИС']
            && $body['messages'][1] === ['role' => 'user', 'content' => 'ЮЗЕР']
            && $body['max_tokens'] === 256
            && $body['response_format'] === ['type' => 'json_object'];
    });
});

it('готовый gpt:// modelUri в конфиге не оборачивается повторно', function () {
    Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'x']]], 'usage' => []])]);

    yandexClient(model: 'gpt://other/yandexgpt/rc')->complete(new LlmRequest(system: 's', user: 'u'));

    Http::assertSent(fn ($req) => $req->data()['model'] === 'gpt://other/yandexgpt/rc');
});

it('маппит HTTP-ошибку провайдера в LlmTransportException', function () {
    Http::fake(['*/chat/completions' => Http::response('upstream boom', 500)]);

    yandexClient()->complete(new LlmRequest(system: 's', user: 'u'));
})->throws(LlmTransportException::class);

it('пустой ключ → AiDisabledException (без сетевого вызова)', function () {
    Http::fake();

    yandexClient(key: '')->complete(new LlmRequest(system: 's', user: 'u'));

    Http::assertNothingSent();
})->throws(AiDisabledException::class);

it('пустой folder → AiDisabledException (без сетевого вызова)', function () {
    Http::fake();

    yandexClient(folder: '')->complete(new LlmRequest(system: 's', user: 'u'));

    Http::assertNothingSent();
})->throws(AiDisabledException::class);

it('200 без choices → пустой content, но usage учтён (без исключения)', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'model' => 'yandexgpt-lite',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 0],
        ]),
    ]);

    $res = yandexClient()->complete(new LlmRequest(system: 's', user: 'u'));

    expect($res->content)->toBe('')
        ->and($res->promptTokens)->toBe(5);
});

it('ConnectionException (таймаут/обрыв TLS) → LlmTransportException', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    yandexClient()->complete(new LlmRequest(system: 's', user: 'u'));
})->throws(LlmTransportException::class);

it('не светит Api-Key в сообщении LlmTransportException (регрессия-замок секрета)', function () {
    Http::fake(['*/chat/completions' => Http::response('upstream boom', 500)]);

    try {
        yandexClient(key: 'super-secret-key-123')->complete(new LlmRequest(system: 's', user: 'u'));
        test()->fail('ожидали LlmTransportException');
    } catch (LlmTransportException $e) {
        expect($e->getMessage())->not->toContain('super-secret-key-123');
    }
});

it('пустой folder допустим, если модель — готовый gpt:// URI (запрос уходит)', function () {
    Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'x']]], 'usage' => []])]);

    yandexClient(folder: '', model: 'gpt://explicit/yandexgpt')->complete(new LlmRequest(system: 's', user: 'u'));

    Http::assertSent(fn ($req) => $req->data()['model'] === 'gpt://explicit/yandexgpt');
});
