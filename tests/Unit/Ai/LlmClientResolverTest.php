<?php

use App\Ai\LlmClientResolver;
use App\Ai\LlmRequest;
use App\Ai\Providers\NullLlmClient;
use App\Ai\Providers\QwenLlmClient;
use App\Ai\Providers\YandexGptLlmClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.enabled', true);
    config()->set('ai.default_tier', 'cheap');
    config()->set('ai.tiers', [
        'cheap' => ['provider' => 'qwen', 'model' => 'qwen-turbo'],
        'strong' => ['provider' => 'qwen', 'model' => 'qwen-max'],
    ]);
    config()->set('ai.providers', [
        'qwen' => ['key' => 'sk-x', 'base' => 'https://example.test/v1', 'timeout' => 30],
    ]);
});

it('резолвит провайдера тира и подставляет его модель', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x']]], 'usage' => []])]);

    $client = (new LlmClientResolver)->for('strong');
    expect($client)->toBeInstanceOf(QwenLlmClient::class);

    $client->complete(new LlmRequest(system: 's', user: 'u'));
    Http::assertSent(fn ($req) => $req->data()['model'] === 'qwen-max');
});

it('default() использует default_tier (cheap)', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x']]], 'usage' => []])]);

    (new LlmClientResolver)->default()->complete(new LlmRequest(system: 's', user: 'u'));
    Http::assertSent(fn ($req) => $req->data()['model'] === 'qwen-turbo');
});

it('при ai.enabled=false всегда отдаёт NullLlmClient', function () {
    config()->set('ai.enabled', false);

    expect((new LlmClientResolver)->for('cheap'))->toBeInstanceOf(NullLlmClient::class)
        ->and((new LlmClientResolver)->for('strong'))->toBeInstanceOf(NullLlmClient::class);
});

it('резолвит yandexgpt: оборачивает modelUri каталогом и шлёт Api-Key', function () {
    config()->set('ai.tiers.cheap', ['provider' => 'yandexgpt', 'model' => 'yandexgpt-lite']);
    config()->set('ai.providers.yandexgpt', ['key' => 'ya-x', 'folder' => 'b1gf', 'base' => 'https://example.test/v1', 'timeout' => 30]);
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x']]], 'usage' => []])]);

    $client = (new LlmClientResolver)->for('cheap');
    expect($client)->toBeInstanceOf(YandexGptLlmClient::class);

    $client->complete(new LlmRequest(system: 's', user: 'u'));
    Http::assertSent(fn ($req) => $req->data()['model'] === 'gpt://b1gf/yandexgpt-lite'
        && $req->hasHeader('Authorization', 'Api-Key ya-x'));
});

it('неизвестный тир → InvalidArgumentException', function () {
    (new LlmClientResolver)->for('ultra');
})->throws(InvalidArgumentException::class);

it('неизвестный провайдер в тире → InvalidArgumentException', function () {
    config()->set('ai.tiers.cheap.provider', 'nope');

    (new LlmClientResolver)->for('cheap');
})->throws(InvalidArgumentException::class);
