<?php

use App\Ai\Exceptions\AiDisabledException;
use App\Ai\LlmRequest;
use App\Ai\Providers\FakeLlmClient;
use App\Ai\Providers\NullLlmClient;

it('NullLlmClient бросает AiDisabledException', function () {
    (new NullLlmClient)->complete(new LlmRequest(system: 's', user: 'u'));
})->throws(AiDisabledException::class);

it('FakeLlmClient возвращает заданный ответ и пишет лог вызовов', function () {
    $fake = FakeLlmClient::returning('{"ok":true}', prompt: 12, completion: 8, model: 'fake-1');

    $req = new LlmRequest(system: 'sys', user: 'usr', jsonMode: true);
    $res = $fake->complete($req);

    expect($res->content)->toBe('{"ok":true}')
        ->and($res->promptTokens)->toBe(12)
        ->and($res->completionTokens)->toBe(8)
        ->and($res->model)->toBe('fake-1')
        ->and($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]->user)->toBe('usr')
        ->and($fake->calls[0]->jsonMode)->toBeTrue();
});
