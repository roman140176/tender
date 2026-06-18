<?php

use App\Adapters\Eis\EisNoticeMapper;
use App\Adapters\Eis\RawTender;
use App\Enums\LawType;

function loadFixtureDomNode(string $fixturePath, string $childLocalName): DOMNode
{
    $doc = new DOMDocument;
    $doc->load($fixturePath);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query("//*[local-name()='{$childLocalName}']");
    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException("Элемент {$childLocalName} не найден в {$fixturePath}");
    }

    return $nodes->item(0);
}

it('extract fz44: возвращает RawTender с корректным regNumber и data', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $node = loadFixtureDomNode(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF'
    );

    $mapper = new EisNoticeMapper;
    $raw = $mapper->extract($node, LawType::Fz44);

    expect($raw)->toBeInstanceOf(RawTender::class)
        ->and($raw->lawType)->toBe(LawType::Fz44)
        ->and($raw->rootElement)->toBe('fcsNotificationEF')
        ->and($raw->regNumber)->toBe('0173200001426000001')
        ->and($raw->data)->toBeArray()
        ->and($raw->data['number'])->toBe('0173200001426000001')
        ->and($raw->data['title'])->toBe('Поставка медицинского оборудования')
        ->and($raw->data['customerName'])->toBe('ГБУЗ Городская больница №1')
        ->and($raw->data['customerInn'])->toBe('7701234567')
        ->and($raw->data['region'])->toBe('77')
        ->and($raw->data['price'])->toBe('1200000.50')
        ->and($raw->data['publishedAt'])->toBe('2026-06-15T10:00:00+03:00')
        ->and($raw->data['deadlineAt'])->toBe('2026-06-25T18:00:00+03:00')
        ->and($raw->data['okpd2'])->toBe(['32.50.13.190'])
        ->and($raw->data['ktru'])->toBe(['32.50.13.190-00000123']);
});

it('extract fz223: возвращает RawTender с корректными полями', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $node = loadFixtureDomNode(
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
        'purchaseNotice'
    );

    $mapper = new EisNoticeMapper;
    $raw = $mapper->extract($node, LawType::Fz223);

    expect($raw)->toBeInstanceOf(RawTender::class)
        ->and($raw->lawType)->toBe(LawType::Fz223)
        ->and($raw->rootElement)->toBe('purchaseNotice')
        ->and($raw->regNumber)->toBe('32212345678')
        ->and($raw->data['title'])->toBe('Оказание услуг по уборке помещений')
        ->and($raw->data['customerName'])->toBe('ПАО Энергосбыт')
        ->and($raw->data['customerInn'])->toBe('5024000123')
        ->and($raw->data['region'])->toBe('78')
        ->and($raw->data['price'])->toBe('450000.00')
        ->and($raw->data['okpd2'])->toBe(['81.21.10'])
        ->and($raw->data['ktru'])->toBe([]);
});

it('extract: InvalidArgumentException при неизвестном lawType-ключе', function () {
    config(['eis' => ['fields' => []]]);
    $doc = new DOMDocument;
    $doc->loadXML('<stub/>');
    $node = $doc->documentElement;

    $mapper = new EisNoticeMapper;
    $mapper->extract($node, LawType::Fz44);
})->throws(InvalidArgumentException::class);
