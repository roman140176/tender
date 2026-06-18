<?php

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\Canonicalizer;
use App\Services\Import\NormalizedTender;

function makeNt(array $overrides = []): NormalizedTender
{
    $title = $overrides['title'] ?? 'Поставка кранов';
    $price = $overrides['price'] ?? null;
    $hash = Canonicalizer::contentHash($title, null, null, null, $price);

    $base = [
        'title' => $title,
        'description' => null,
        'customerName' => null,
        'customerInn' => null,
        'region' => null,
        'price' => $price,
        'currency' => 'RUB',
        'lawType' => LawType::Fz44,
        'procedureType' => null,
        'sourceStatus' => TenderSourceStatus::Active,
        'publishedAt' => null,
        'deadlineAt' => null,
        'sourceUrl' => '',
        'number' => null,
        'okpd2' => [],
        'contentHash' => $hash,
    ];

    if (isset($overrides['ktru'])) {
        $base['ktru'] = $overrides['ktru'];
    }

    if (isset($overrides['extra'])) {
        $base['extra'] = $overrides['extra'];
    }

    return new NormalizedTender(...$base);
}

it('old call without ktru/extra works — defaults to empty arrays', function () {
    $nt = makeNt();

    expect($nt->ktru)->toBe([])
        ->and($nt->extra)->toBe([]);
});

it('ktru and extra are accessible when passed', function () {
    $nt = makeNt([
        'ktru' => ['32.50.13.190-00000123'],
        'extra' => ['rootElement' => 'fcsNotificationEF'],
    ]);

    expect($nt->ktru)->toBe(['32.50.13.190-00000123'])
        ->and($nt->extra)->toHaveKey('rootElement', 'fcsNotificationEF');
});

it('NormalizedTender is readonly — fields cannot be overwritten', function () {
    $nt = makeNt(['ktru' => ['A']]);

    expect(fn () => $nt->ktru = ['B'])->toThrow(Error::class);
});
