<?php

use App\Notifications\HttpMaxGateway;
use App\Notifications\MaxGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('sendMessage шлёт POST на MAX Bot API /messages с chat_id в query и токеном в заголовке', function () {
    config([
        'services.max.bot_token' => 'MY_MAX_TOKEN',
        'services.max.api_base' => 'https://platform-api.max.ru',
    ]);

    Http::fake([
        'platform-api.max.ru/*' => Http::response(['message_id' => 1], 200),
    ]);

    $gateway = new HttpMaxGateway;
    $gateway->sendMessage('123456', 'привет');

    Http::assertSent(function (Request $request) {
        $url = (string) $request->url();

        return $request->method() === 'POST'
            && str_contains($url, '/messages')
            && str_contains($url, 'chat_id=123456')
            && $request->header('Authorization')[0] === 'MY_MAX_TOKEN'
            && $request['text'] === 'привет'
            && $request['format'] === 'html';
    });
});

it('sendMessage прокидывает дополнительные opts в тело', function () {
    config([
        'services.max.bot_token' => 'MY_MAX_TOKEN',
        'services.max.api_base' => 'https://platform-api.max.ru',
    ]);

    Http::fake([
        'platform-api.max.ru/*' => Http::response(['message_id' => 1], 200),
    ]);

    (new HttpMaxGateway)->sendMessage('123456', 'plain', ['notify' => false]);

    Http::assertSent(fn (Request $request) => $request['notify'] === false);
});

it('sendMessage использует api_base по умолчанию, если не задан', function () {
    config(['services.max.bot_token' => 'MY_MAX_TOKEN', 'services.max.api_base' => null]);

    Http::fake(['platform-api.max.ru/*' => Http::response(['message_id' => 1], 200)]);

    (new HttpMaxGateway)->sendMessage('1', 'x');

    Http::assertSent(fn (Request $request) => str_starts_with((string) $request->url(), 'https://platform-api.max.ru/'));
});

it('sendMessage бросает RequestException при HTTP 500 (для джоб-ретраев)', function () {
    config(['services.max.bot_token' => 'MY_MAX_TOKEN']);

    Http::fake(['platform-api.max.ru/*' => Http::response('server error', 500)]);

    expect(fn () => (new HttpMaxGateway)->sendMessage('1', 'x'))
        ->toThrow(RequestException::class);
});

it('sendMessage бросает RequestException при HTTP 400 (для джоб-ретраев)', function () {
    config(['services.max.bot_token' => 'MY_MAX_TOKEN']);

    Http::fake(['platform-api.max.ru/*' => Http::response(['error' => 'bad request'], 400)]);

    expect(fn () => (new HttpMaxGateway)->sendMessage('1', 'x'))
        ->toThrow(RequestException::class);
});

it('токен не появляется в сообщении исключения при HTTP-ошибке', function () {
    config(['services.max.bot_token' => 'SUPER_SECRET_TOKEN']);

    Http::fake(['platform-api.max.ru/*' => Http::response('fail', 500)]);

    $message = '';
    try {
        (new HttpMaxGateway)->sendMessage('1', 'x');
    } catch (RequestException $e) {
        $message = $e->getMessage();
    }

    // Токен в заголовке — не в URL, поэтому не попадает в RequestException message
    expect($message)->not->toContain('SUPER_SECRET_TOKEN');
});

it('биндинг контейнера резолвит MaxGateway в HttpMaxGateway', function () {
    expect(app(MaxGateway::class))->toBeInstanceOf(HttpMaxGateway::class);
});
