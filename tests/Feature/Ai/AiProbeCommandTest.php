<?php

use Illuminate\Support\Facades\Http;

function configureProbe(array $overrides = []): void
{
    config()->set('ai.enabled', $overrides['enabled'] ?? true);
    config()->set('ai.default_tier', 'cheap');
    config()->set('ai.tiers.cheap', ['provider' => 'yandexgpt', 'model' => 'yandexgpt-lite']);
    config()->set('ai.tiers.strong', ['provider' => 'yandexgpt', 'model' => 'yandexgpt']);
    config()->set('ai.providers.yandexgpt', ['key' => 'ya-x', 'folder' => 'b1gf', 'base' => 'https://example.test/v1', 'timeout' => 30]);
}

it('успешный вызов печатает ответ, токены и завершает 0', function () {
    configureProbe();
    Http::fake(['*' => Http::response([
        'model' => 'yandexgpt-lite',
        'choices' => [['message' => ['content' => 'Чтобы не упустить контракты.']]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $this->artisan('ai:probe')
        ->expectsOutputToContain('Чтобы не упустить контракты.')
        ->expectsOutputToContain('yandexgpt')
        ->assertExitCode(0);
});

it('AI выключен → понятная диагностика и код возврата 1', function () {
    configureProbe(['enabled' => false]);
    Http::fake();

    $this->artisan('ai:probe')
        ->expectsOutputToContain('недоступен')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('HTTP-ошибка провайдера → транспортная диагностика и код возврата 1', function () {
    configureProbe();
    Http::fake(['*' => Http::response('upstream boom', 500)]);

    $this->artisan('ai:probe')
        ->expectsOutputToContain('HTTP')
        ->assertExitCode(1);
});

it('передаёт --tier strong в резолвер (модель сильного тира)', function () {
    configureProbe();
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    $this->artisan('ai:probe', ['--tier' => 'strong'])->assertExitCode(0);

    Http::assertSent(fn ($req) => $req->data()['model'] === 'gpt://b1gf/yandexgpt');
});
