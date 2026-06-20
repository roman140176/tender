<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Mail\TenderNotificationMail;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use Illuminate\Support\Carbon;

function mailFixtureMatch(array $tenderAttrs = [], array $matchAttrs = []): TenderMatch
{
    $source = new Source(['code' => 'eis', 'name' => 'ЕИС (zakupki.gov.ru)']);

    $tender = new Tender(array_merge([
        'title' => 'Поставка кранов мостовых',
        'region' => 'Москва',
        'price' => '1500000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_url' => 'https://zakupki.gov.ru/notice/0173200001426000001',
    ], $tenderAttrs));
    $tender->setRelation('source', $source);

    $match = new TenderMatch(array_merge([
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [
            ['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'кран'],
            ['rule' => 'priority_region', 'points' => 10, 'detail' => 'Москва'],
        ],
        'risk_flags' => ['deadline_soon'],
    ], $matchAttrs));
    $match->setRelation('tender', $tender);

    return $match;
}

it('subject содержит заголовок закупки', function () {
    $mail = new TenderNotificationMail(mailFixtureMatch());

    expect($mail->envelope()->subject)->toBe('🔔 Новая релевантная закупка: Поставка кранов мостовых');
});

it('тело письма содержит заголовок, цену, источник, причины, риски и ссылку', function () {
    $mail = new TenderNotificationMail(mailFixtureMatch());
    $html = $mail->render();

    expect($html)->toContain('Поставка кранов мостовых')
        ->and($html)->toContain('1 500 000,00 ₽')
        ->and($html)->toContain('Москва')
        ->and($html)->toContain('01.07.2026 18:00')
        ->and($html)->toContain('ЕИС (zakupki.gov.ru)')
        ->and($html)->toContain('Средняя')
        ->and($html)->toContain('Ключ в названии: «кран» (+20)')
        ->and($html)->toContain('Скоро дедлайн')
        ->and($html)->toContain('Посмотреть ТЗ')
        ->and($html)->toContain('href="https://zakupki.gov.ru/notice/0173200001426000001"');
});

it('Blade авто-экранирует HTML-спецсимволы в заголовке', function () {
    $mail = new TenderNotificationMail(mailFixtureMatch(['title' => 'Краны <b>X</b> & Y']));
    $html = $mail->render();

    expect($html)->toContain('Краны &lt;b&gt;X&lt;/b&gt; &amp; Y')
        ->and($html)->not->toContain('Краны <b>X</b>');
});

it('для не-http(s) source_url ссылку не делает кликабельной, показывает как текст', function () {
    $mail = new TenderNotificationMail(mailFixtureMatch(['source_url' => 'javascript:alert(1)']));
    $html = $mail->render();

    expect($html)->not->toContain('href="javascript:alert(1)"')
        ->and($html)->toContain('javascript:alert(1)');
});

it('подставляет заглушки при null-полях', function () {
    $mail = new TenderNotificationMail(mailFixtureMatch([
        'region' => null,
        'price' => null,
        'deadline_at' => null,
    ], [
        'level' => RelevanceLevel::Weak,
        'action_recommendation' => ActionRecommendation::Monitor,
        'reason' => [],
        'risk_flags' => [],
    ]));
    $html = $mail->render();

    expect($html)->toContain('не указана')
        ->and($html)->toContain('не указан')
        ->and($html)->toContain('Слабая')
        ->and($html)->toContain('Мониторить');
});
