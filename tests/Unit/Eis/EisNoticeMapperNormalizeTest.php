<?php

use App\Adapters\Eis\EisNoticeMapper;
use App\Adapters\Eis\RawTender;
use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\NormalizationException;
use App\Services\Import\NormalizedTender;

// Переиспользуем хелпер из Task 9 — загружает DOM-узел из фикстуры
function fixtureRawTender(string $fixturePath, string $childLocalName, LawType $law): RawTender
{
    $doc = new DOMDocument;
    $doc->load($fixturePath);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query("//*[local-name()='{$childLocalName}']");
    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException("Элемент {$childLocalName} не найден");
    }
    $node = $nodes->item(0);
    $mapper = new EisNoticeMapper;

    return $mapper->extract($node, $law);
}

beforeEach(function () {
    config(['eis' => require base_path('config/eis.php')]);
});

it('toNormalizedTender fz44: поля маппятся корректно', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    expect($n)->toBeInstanceOf(NormalizedTender::class)
        ->and($n->title)->toBe('Поставка медицинского оборудования')
        ->and($n->lawType)->toBe(LawType::Fz44)
        ->and($n->number)->toBe('0173200001426000001')
        ->and($n->customerName)->toBe('ГБУЗ Городская больница №1')
        ->and($n->customerInn)->toBe('7701234567')
        ->and($n->region)->toBe('77')
        ->and($n->price)->toBe('1200000.50')
        ->and($n->currency)->toBe('RUB')
        ->and($n->sourceStatus)->toBe(TenderSourceStatus::Active)
        ->and($n->okpd2)->toBe(['32.50.13.190'])
        ->and($n->ktru)->toBe(['32.50.13.190-00000123']);
});

it('toNormalizedTender fz44: publishedAt конвертируется в UTC (+03:00 → 07:00 UTC)', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    // 2026-06-15T10:00:00+03:00 → UTC = 2026-06-15 07:00:00
    expect($n->publishedAt?->format('Y-m-d H:i:s'))->toBe('2026-06-15 07:00:00')
        ->and($n->publishedAt?->timezone->getName())->toBe('UTC');

    // 2026-06-25T18:00:00+03:00 → UTC = 2026-06-25 15:00:00
    expect($n->deadlineAt?->format('Y-m-d H:i:s'))->toBe('2026-06-25 15:00:00')
        ->and($n->deadlineAt?->timezone->getName())->toBe('UTC');
});

it('toNormalizedTender fz44: contentHash детерминирован и длиной 64', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper;
    $n1 = $mapper->toNormalizedTender($raw);
    $n2 = $mapper->toNormalizedTender($raw);

    expect($n1->contentHash)->toHaveLength(64)
        ->and($n1->contentHash)->toBe($n2->contentHash);
});

it('toNormalizedTender fz44: sourceUrl содержит regNumber', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    expect($n->sourceUrl)->toBe(
        'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=0173200001426000001'
    );
});

it('toNormalizedTender fz223: поля маппятся корректно', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
        'purchaseNotice',
        LawType::Fz223
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    expect($n)->toBeInstanceOf(NormalizedTender::class)
        ->and($n->title)->toBe('Оказание услуг по уборке помещений')
        ->and($n->lawType)->toBe(LawType::Fz223)
        ->and($n->number)->toBe('32212345678')
        ->and($n->price)->toBe('450000.00')
        ->and($n->okpd2)->toBe(['81.21.10'])
        ->and($n->ktru)->toBe([]);
});

it('toNormalizedTender fz223: publishedAt в UTC (+03:00 → 06:30 UTC)', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
        'purchaseNotice',
        LawType::Fz223
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    // 2026-06-16T09:30:00+03:00 → UTC = 2026-06-16 06:30:00
    expect($n->publishedAt?->format('Y-m-d H:i:s'))->toBe('2026-06-16 06:30:00')
        ->and($n->publishedAt?->timezone->getName())->toBe('UTC');
});

it('toNormalizedTender: пустой title → NormalizationException', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: [
            'number' => '0000000000000000001',
            'title' => '',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt' => '',
            'customerName' => 'Тест',
            'customerInn' => '7700000000',
            'region' => '77',
            'price' => '100.00',
            'okpd2' => [],
            'ktru' => [],
        ],
        regNumber: '0000000000000000001',
    );

    $mapper = new EisNoticeMapper;
    $mapper->toNormalizedTender($raw);
})->throws(NormalizationException::class, 'Пустое название закупки в ЕИС-извещении.');

it('toNormalizedTender fz44: Cancel в rootElement → sourceStatus Cancelled', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEFCancel',
        data: [
            'number' => '0173200001426000002',
            'title' => 'Отмена закупки',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt' => '',
            'customerName' => 'Тест',
            'customerInn' => '7700000001',
            'region' => '77',
            'price' => '0',
            'okpd2' => [],
            'ktru' => [],
        ],
        regNumber: '0173200001426000002',
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    expect($n->sourceStatus)->toBe(TenderSourceStatus::Cancelled);
});

it('toNormalizedTender: нулевая цена (0) валидна → price="0.00"', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: [
            'number' => '0173200001426000003',
            'title' => 'Закупка с нулевой ценой',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt' => '',
            'customerName' => 'Тест',
            'customerInn' => '7700000002',
            'region' => '77',
            'price' => '0',
            'okpd2' => [],
            'ktru' => [],
        ],
        regNumber: '0173200001426000003',
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    expect($n->price)->toBe('0.00');
});

it('toNormalizedTender: нечисловая цена → price=null', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: [
            'number' => '0173200001426000004',
            'title' => 'Закупка без цены',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt' => '',
            'customerName' => 'Тест',
            'customerInn' => '7700000003',
            'region' => '77',
            'price' => 'договорная',
            'okpd2' => [],
            'ktru' => [],
        ],
        regNumber: '0173200001426000004',
    );

    $mapper = new EisNoticeMapper;
    $n = $mapper->toNormalizedTender($raw);

    expect($n->price)->toBeNull();
});
