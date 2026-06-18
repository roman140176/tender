<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Adapters\Eis\RawTender;
use App\Adapters\Eis\RawTenderDetails;
use App\Enums\LawType;
use Carbon\CarbonImmutable;

use function Tests\Support\makeEisArchive;

it('app(EisAdapter::class) резолвится через IoC-контейнер', function () {
    $adapter = app(EisAdapter::class);
    expect($adapter)->toBeInstanceOf(EisAdapter::class);
});

it('sourceCode возвращает "eis"', function () {
    $adapter = app(EisAdapter::class);
    expect($adapter->sourceCode())->toBe('eis');
});

it('collect() с фейк-gateway возвращает RawTender из fz44_notice', function () {
    $zipPath = makeEisArchive([
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
    ]);

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/eis-integration/download/some.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('listPackages')
        ->once()
        ->andReturn([$ref]);
    $fakeGateway->shouldReceive('downloadArchive')
        ->once()
        ->andReturnUsing(function (PackageRef $r, string $dest) use ($zipPath) {
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($zipPath, $dest);
        });

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);

    $params = new CollectParams(
        since: CarbonImmutable::parse('2026-06-15', 'UTC'),
        until: CarbonImmutable::parse('2026-06-15', 'UTC'),
        regions: ['77'],
        subsystemTypes: ['PRIZ'],
    );

    $results = iterator_to_array($adapter->collect($params));

    expect($results)->toHaveCount(1);
    expect($results[0])->toBeInstanceOf(RawTender::class);
    expect($results[0]->regNumber)->toBe('0173200001426000001');
    expect($results[0]->lawType)->toBe(LawType::Fz44);

    @unlink($zipPath);
});

it('details() возвращает RawTenderDetails при наличии XML', function () {
    // fcsNotificationEF — минимальный XML без namespace-оберток export
    $noticeXml = '<?xml version="1.0" encoding="UTF-8"?>
<fcsNotificationEF>
  <purchaseNumber>0173200001426000001</purchaseNumber>
  <purchaseObjectInfo>Поставка оборудования</purchaseObjectInfo>
  <docPublishDate>2026-06-15T10:00:00+03:00</docPublishDate>
  <purchaseResponsible><responsibleOrg>
    <fullName>ГБУЗ Тест</fullName><INN>7701234567</INN><regionCode>77</regionCode>
  </responsibleOrg></purchaseResponsible>
  <notificationInfo><procedureInfo><collecting><endDate>2026-06-25T18:00:00+03:00</endDate></collecting></procedureInfo></notificationInfo>
  <lot><maxPrice>100000.00</maxPrice></lot>
</fcsNotificationEF>';

    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('fetchByReestrNumber')
        ->once()
        ->with('0173200001426000001')
        ->andReturn($noticeXml);

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);
    $result = $adapter->details('0173200001426000001');

    expect($result)->toBeInstanceOf(RawTenderDetails::class);
    expect($result->raw)->toBeInstanceOf(RawTender::class);
    expect($result->raw->regNumber)->toBe('0173200001426000001');
    expect($result->rawXml)->toBe($noticeXml);
});

it('details() возвращает null если gateway вернул null', function () {
    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('fetchByReestrNumber')
        ->once()
        ->andReturn(null);

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);
    expect($adapter->details('9999999999'))->toBeNull();
});

it('details() возвращает null при неизвестном root-элементе', function () {
    $unknownXml = '<?xml version="1.0"?><someUnknownElement><foo>bar</foo></someUnknownElement>';

    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('fetchByReestrNumber')
        ->once()
        ->andReturn($unknownXml);

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);
    expect($adapter->details('123'))->toBeNull();
});
