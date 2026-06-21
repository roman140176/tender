<?php

use App\Ai\Exceptions\AiDisabledException;
use App\Ai\Exceptions\AiException;
use App\Ai\Exceptions\LlmTransportException;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;

it('LlmRequest держит значения и имеет дефолты', function () {
    $req = new LlmRequest(system: 'sys', user: 'usr');

    expect($req->system)->toBe('sys')
        ->and($req->user)->toBe('usr')
        ->and($req->jsonMode)->toBeFalse()
        ->and($req->maxTokens)->toBeNull();
});

it('LlmRequest принимает jsonMode и maxTokens', function () {
    $req = new LlmRequest(system: 's', user: 'u', jsonMode: true, maxTokens: 512);

    expect($req->jsonMode)->toBeTrue()
        ->and($req->maxTokens)->toBe(512);
});

it('LlmResponse держит значения и считает суммарные токены', function () {
    $res = new LlmResponse(content: 'hi', promptTokens: 10, completionTokens: 5, model: 'qwen-turbo', provider: 'qwen');

    expect($res->content)->toBe('hi')
        ->and($res->promptTokens)->toBe(10)
        ->and($res->completionTokens)->toBe(5)
        ->and($res->model)->toBe('qwen-turbo')
        ->and($res->provider)->toBe('qwen')
        ->and($res->totalTokens())->toBe(15);
});

it('иерархия исключений AI наследуется от AiException', function () {
    expect(new AiDisabledException)->toBeInstanceOf(AiException::class)
        ->and(new LlmTransportException('boom'))->toBeInstanceOf(AiException::class)
        ->and(new AiException('x'))->toBeInstanceOf(RuntimeException::class);
});
