<?php

use App\Scoring\MatchReasonPresenter;

it('lines() рендерит правила в рус. строки по имени ключа (устойчиво к порядку jsonb)', function () {
    // ключи нарочно в «переупорядоченном» виде, как их отдаёт PG jsonb
    $reason = [
        ['points' => 30, 'detail' => 'краны мостовые', 'rule' => 'exact_in_title'],
        ['detail' => '1500000.00 ≥ 1000000.00', 'rule' => 'price_above_min', 'points' => 20],
    ];

    $lines = MatchReasonPresenter::lines($reason);

    expect($lines)->toBe([
        'Точная фраза в названии: «краны мостовые» (+30)',
        'Цена не ниже минимума: 1500000.00 ≥ 1000000.00 (+20)',
    ]);
});

it('lines() показывает отрицательные баллы со знаком', function () {
    $lines = MatchReasonPresenter::lines([
        ['rule' => 'source_closed', 'points' => -30, 'detail' => 'completed'],
    ]);

    expect($lines)->toBe(['Закупка закрыта/отменена (−30)']);
});

it('lines() для неизвестного правила даёт дефолтную строку, не падает', function () {
    $lines = MatchReasonPresenter::lines([['rule' => 'unknown_rule', 'points' => 7, 'detail' => 'x']]);

    expect($lines)->toBe(['unknown_rule: x (+7)']);
});

it('risks() переводит флаги в рус. строки, неизвестные пропускает как есть', function () {
    expect(MatchReasonPresenter::risks(['deadline_soon', 'no_price', 'source_closed', 'no_deadline']))
        ->toBe([
            'Скоро дедлайн',
            'Цена не указана',
            'Источник закрыт',
            'Дедлайн не указан',
        ]);
});
