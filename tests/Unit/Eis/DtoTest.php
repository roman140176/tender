<?php

use App\Adapters\Contracts\TenderSourceAdapter;
use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Adapters\Eis\RawTender;
use App\Adapters\Eis\RawTenderDetails;
use App\Enums\LawType;
use Carbon\CarbonImmutable;

it('CollectParams holds since/until/regions/subsystemTypes', function () {
    $since = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC');
    $until = CarbonImmutable::parse('2026-06-17 00:00:00', 'UTC');
    $params = new CollectParams($since, $until, ['77', '78'], ['PRIZ', 'RI223']);

    expect($params->since)->toBe($since);
    expect($params->until)->toBe($until);
    expect($params->regions)->toBe(['77', '78']);
    expect($params->subsystemTypes)->toBe(['PRIZ', 'RI223']);
});

it('PackageRef holds all fields including lawType enum', function () {
    $ref = new PackageRef(
        archiveUrl: 'https://example.com/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    expect($ref->archiveUrl)->toBe('https://example.com/archive.zip');
    expect($ref->region)->toBe('77');
    expect($ref->subsystemType)->toBe('PRIZ');
    expect($ref->date)->toBe('2026-06-15');
    expect($ref->lawType)->toBe(LawType::Fz44);
});

it('RawTender holds lawType, rootElement, data, regNumber', function () {
    $raw = new RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: ['purchaseNumber' => '0173200001426000001', 'maxPrice' => '1200000.50'],
        regNumber: '0173200001426000001',
    );

    expect($raw->lawType)->toBe(LawType::Fz44);
    expect($raw->rootElement)->toBe('fcsNotificationEF');
    expect($raw->data['purchaseNumber'])->toBe('0173200001426000001');
    expect($raw->regNumber)->toBe('0173200001426000001');
});

it('RawTender regNumber can be null', function () {
    $raw = new RawTender(
        lawType: LawType::Fz223,
        rootElement: 'purchaseNotice',
        data: [],
        regNumber: null,
    );

    expect($raw->regNumber)->toBeNull();
});

it('RawTenderDetails holds raw and rawXml', function () {
    $raw = new RawTender(LawType::Fz44, 'fcsNotificationEF', [], '0173200001426000001');
    $details = new RawTenderDetails($raw, '<xml/>');

    expect($details->raw)->toBe($raw);
    expect($details->rawXml)->toBe('<xml/>');
});

it('CollectParams is readonly — mutation throws Error', function () {
    $params = new CollectParams(
        CarbonImmutable::now(),
        CarbonImmutable::now(),
        ['77'],
        ['PRIZ'],
    );

    expect(fn () => $params->regions = ['99'])->toThrow(Error::class);
});

it('TenderSourceAdapter interface exists and declares required methods', function () {
    $iface = TenderSourceAdapter::class;
    expect(interface_exists($iface))->toBeTrue();

    $methods = get_class_methods($iface) ?? [];
    // interface_exists не возвращает методы через get_class_methods для интерфейса напрямую,
    // используем ReflectionClass
    $ref = new ReflectionClass($iface);
    $methodNames = array_map(fn ($m) => $m->getName(), $ref->getMethods());

    expect($methodNames)->toContain('sourceCode');
    expect($methodNames)->toContain('collect');
    expect($methodNames)->toContain('normalize');
    expect($methodNames)->toContain('details');
});

it('EisGateway interface exists and declares required methods', function () {
    $iface = EisGateway::class;
    expect(interface_exists($iface))->toBeTrue();

    $ref = new ReflectionClass($iface);
    $methodNames = array_map(fn ($m) => $m->getName(), $ref->getMethods());

    expect($methodNames)->toContain('listPackages');
    expect($methodNames)->toContain('downloadArchive');
    expect($methodNames)->toContain('fetchByReestrNumber');
});
