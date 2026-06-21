<?php

use App\Notifications\FakeMaxGateway;
use App\Notifications\MaxGateway;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-21 09:00:00');
    config()->set('eis.token_alert_days', 30);
    config()->set('eis.ops_max_chat_id', 'ops-123');
    /** @var FakeMaxGateway $fake */
    $fake = $this->app->make(MaxGateway::class);
    $this->maxFake = $fake;
});

it('молчит, если до протуха токена далеко', function () {
    config()->set('eis.token_expires_at', '2026-12-31'); // > 30 дней

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->sent)->toBeEmpty();
});

it('шлёт MAX-алерт в ops-чат, когда до протуха < порога', function () {
    config()->set('eis.token_expires_at', '2026-07-10'); // 19 дней

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->messagesTo('ops-123'))->toHaveCount(1)
        ->and($this->maxFake->sent[0]['text'])->toContain('19');
});

it('шлёт алерт, если токен уже протух', function () {
    config()->set('eis.token_expires_at', '2026-06-01'); // в прошлом

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->messagesTo('ops-123'))->toHaveCount(1)
        ->and($this->maxFake->sent[0]['text'])->toContain('ПРОТЁК')
        ->and($this->maxFake->sent[0]['text'])->toContain('2026-06-01');
});

it('не падает и не шлёт, если EIS_TOKEN_EXPIRES_AT не задан', function () {
    config()->set('eis.token_expires_at', null);

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->sent)->toBeEmpty();
});

it('без ops-чата алерт не шлётся, команда не падает', function () {
    config()->set('eis.token_expires_at', '2026-07-01'); // в пороге
    config()->set('eis.ops_max_chat_id', null);

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->sent)->toBeEmpty();
});

it('граница включающая: daysLeft ровно == порога → алерт шлётся', function () {
    config()->set('eis.token_expires_at', '2026-07-21'); // ровно 30 дн.

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->messagesTo('ops-123'))->toHaveCount(1);
});

it('граница: daysLeft == порог+1 → молчит', function () {
    config()->set('eis.token_expires_at', '2026-07-22'); // 31 дн.

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->sent)->toBeEmpty();
});

it('синтаксический мусор в дате не валит команду и не шлёт ложный алерт', function () {
    config()->set('eis.token_expires_at', 'не-дата');

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->sent)->toBeEmpty();
});

it('семантически невалидная дата (2026-02-30) отвергается, а не молча переносится', function () {
    config()->set('eis.token_expires_at', '2026-02-30');

    $this->artisan('eis:token-check')->assertExitCode(0);

    expect($this->maxFake->sent)->toBeEmpty();
});

it('падение MaxGateway при отправке алерта НЕ валит команду', function () {
    config()->set('eis.token_expires_at', '2026-07-01'); // в пороге
    $this->maxFake->throwOnSend = new RuntimeException('MAX down');

    $this->artisan('eis:token-check')->assertExitCode(0);
});
