<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Notifications\MaxNotificationPresenter;
use Illuminate\Support\Carbon;

it('рендерит все поля §14.1 детерминированно', function () {
    $source = new Source(['code' => 'eis', 'name' => 'ЕИС (zakupki.gov.ru)']);

    $tender = new Tender([
        'title' => 'Поставка кранов <мостовых> & опорных',
        'region' => 'Москва',
        'price' => '1500000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_url' => 'https://zakupki.gov.ru/notice/0173200001426000001',
    ]);
    $tender->setRelation('source', $source);

    $match = new TenderMatch([
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [
            ['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'кран'],
            ['rule' => 'priority_region', 'points' => 10, 'detail' => 'Москва'],
        ],
        'risk_flags' => ['deadline_soon', 'no_price'],
    ]);
    $match->setRelation('tender', $tender);

    $text = MaxNotificationPresenter::render($match);

    // Название — экранировано (HTML-спецсимволы не ломают разметку)
    expect($text)->toContain('Поставка кранов &lt;мостовых&gt; &amp; опорных')
        ->and($text)->not->toContain('Поставка кранов <мостовых>')
        // Сумма
        ->and($text)->toContain('1 500 000,00 ₽')
        // Регион
        ->and($text)->toContain('Москва')
        // Срок подачи
        ->and($text)->toContain('01.07.2026 18:00')
        // Источник
        ->and($text)->toContain('ЕИС (zakupki.gov.ru)')
        // Релевантность
        ->and($text)->toContain('Средняя')
        // Почему подходит (reason через MatchReasonPresenter)
        ->and($text)->toContain('Ключ в названии: «кран» (+20)')
        ->and($text)->toContain('Приоритетный регион: Москва (+10)')
        // Риски
        ->and($text)->toContain('Скоро дедлайн')
        ->and($text)->toContain('Цена не указана')
        // Рекомендация
        ->and($text)->toContain('Посмотреть ТЗ')
        // Ссылка
        ->and($text)->toContain('https://zakupki.gov.ru/notice/0173200001426000001');
});

it('не рендерит ссылку для не-http(s) source_url (allowlist схемы), показывает как текст', function () {
    $source = new Source(['code' => 'eis', 'name' => 'ЕИС']);
    $tender = new Tender([
        'title' => 'Опасный URL',
        'region' => 'Москва',
        'price' => '100.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_url' => 'javascript:alert(1)',
    ]);
    $tender->setRelation('source', $source);

    $match = new TenderMatch([
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [],
        'risk_flags' => [],
    ]);
    $match->setRelation('tender', $tender);

    $text = MaxNotificationPresenter::render($match);

    // Не должно быть кликабельной ссылки на javascript:-схему
    expect($text)->not->toContain('<a href=')
        // URL всё равно показан как экранированный текст
        ->and($text)->toContain('javascript:alert(1)');
});

it('рендерит ссылку только для http(s) source_url', function () {
    $source = new Source(['code' => 'eis', 'name' => 'ЕИС']);
    $tender = new Tender([
        'title' => 'Нормальный URL',
        'region' => 'Москва',
        'price' => '100.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_url' => 'https://zakupki.gov.ru/notice/123',
    ]);
    $tender->setRelation('source', $source);

    $match = new TenderMatch([
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [],
        'risk_flags' => [],
    ]);
    $match->setRelation('tender', $tender);

    $text = MaxNotificationPresenter::render($match);

    expect($text)->toContain('<a href="https://zakupki.gov.ru/notice/123">Открыть закупку</a>');
});

it('подставляет заглушки при null-полях', function () {
    $source = new Source(['code' => 'manual', 'name' => 'Ручной импорт']);
    $tender = new Tender([
        'title' => 'Закупка без деталей',
        'region' => null,
        'price' => null,
        'deadline_at' => null,
        'source_url' => 'https://example.test/x',
    ]);
    $tender->setRelation('source', $source);

    $match = new TenderMatch([
        'score' => 40,
        'level' => RelevanceLevel::Weak,
        'action_recommendation' => ActionRecommendation::Monitor,
        'reason' => [],
        'risk_flags' => [],
    ]);
    $match->setRelation('tender', $tender);

    $text = MaxNotificationPresenter::render($match);

    expect($text)->toContain('<b>Цена:</b> не указана')
        ->and($text)->toContain('<b>Регион:</b> не указан')
        ->and($text)->toContain('<b>Срок подачи:</b> не указан')
        ->and($text)->toContain('Слабая')
        ->and($text)->toContain('Мониторить');
});
