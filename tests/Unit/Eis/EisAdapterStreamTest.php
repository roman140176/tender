<?php

use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\EisNoticeMapper;
use App\Adapters\Eis\RawTender;
use App\Enums\LawType;
use App\Enums\TenderSourceStatus;

use function Tests\Support\makeEisArchive;

// streamNotices: fz44 ZIP → 1 RawTender с корректным rootElement

it('streamNotices возвращает 1 RawTender из fz44_notice.xml', function () {
    $zipPath = makeEisArchive([
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
    ]);

    $mapper = new EisNoticeMapper;
    $gateway = Mockery::mock(EisGateway::class);
    $adapter = new EisAdapter($gateway, $mapper);

    $results = iterator_to_array($adapter->streamNotices($zipPath, LawType::Fz44));

    expect($results)->toHaveCount(1);
    expect($results[0])->toBeInstanceOf(RawTender::class);
    expect($results[0]->rootElement)->toBe('fcsNotificationEF');
    expect($results[0]->lawType)->toBe(LawType::Fz44);
    expect($results[0]->regNumber)->toBe('0173200001426000001');

    @unlink($zipPath);
});

it('streamNotices возвращает 1 RawTender из fz223_notice.xml', function () {
    $zipPath = makeEisArchive([
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
    ]);

    $mapper = new EisNoticeMapper;
    $gateway = Mockery::mock(EisGateway::class);
    $adapter = new EisAdapter($gateway, $mapper);

    $results = iterator_to_array($adapter->streamNotices($zipPath, LawType::Fz223));

    expect($results)->toHaveCount(1);
    expect($results[0])->toBeInstanceOf(RawTender::class);
    expect($results[0]->rootElement)->toBe('purchaseNotice');
    expect($results[0]->lawType)->toBe(LawType::Fz223);
    expect($results[0]->regNumber)->toBe('32212345678');

    @unlink($zipPath);
});

it('streamNotices пропускает XML-файлы без известных root-элементов', function () {
    // Создаём временный XML без известного корня
    $tmpXml = tempnam(sys_get_temp_dir(), 'eis_unknown_').'.xml';
    file_put_contents($tmpXml, '<?xml version="1.0" encoding="UTF-8"?><unknownRoot><foo>bar</foo></unknownRoot>');

    $zipPath = makeEisArchive([$tmpXml]);

    $mapper = new EisNoticeMapper;
    $gateway = Mockery::mock(EisGateway::class);
    $adapter = new EisAdapter($gateway, $mapper);

    $results = iterator_to_array($adapter->streamNotices($zipPath, LawType::Fz44));

    expect($results)->toHaveCount(0);

    @unlink($tmpXml);
    @unlink($zipPath);
});

it('adapter->normalize через mapper возвращает NormalizedTender из fz44_notice', function () {
    $zipPath = makeEisArchive([
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
    ]);

    $mapper = new EisNoticeMapper;
    $gateway = Mockery::mock(EisGateway::class);
    $adapter = new EisAdapter($gateway, $mapper);

    $raws = iterator_to_array($adapter->streamNotices($zipPath, LawType::Fz44));
    $normalized = $adapter->normalize($raws[0]);

    expect($normalized->title)->toBe('Поставка медицинского оборудования');
    expect($normalized->number)->toBe('0173200001426000001');
    expect($normalized->lawType)->toBe(LawType::Fz44);
    expect($normalized->price)->toBe('1200000.50');
    expect($normalized->currency)->toBe('RUB');
    expect($normalized->customerInn)->toBe('7701234567');
    expect($normalized->region)->toBe('77');
    // offset-aware: +03:00 → UTC = 07:00 предыдущего дня нет, т.к. 10:00+03 = 07:00 UTC того же дня
    expect($normalized->publishedAt?->format('Y-m-d'))->toBe('2026-06-15');
    expect($normalized->publishedAt?->timezone->getName())->toBe('UTC');
    expect($normalized->deadlineAt?->format('Y-m-d'))->toBe('2026-06-25');
    expect($normalized->sourceStatus)->toBe(TenderSourceStatus::Active);
    expect($normalized->okpd2)->toBe(['32.50.13.190']);
    expect($normalized->ktru)->toBe(['32.50.13.190-00000123']);

    @unlink($zipPath);
});

it('adapter->normalize из fz44 Cancel-извещения даёт sourceStatus=Cancelled', function () {
    $tmpXml = tempnam(sys_get_temp_dir(), 'eis_cancel_').'.xml';
    file_put_contents($tmpXml, '<?xml version="1.0" encoding="UTF-8"?>
<ns2:export xmlns:ns2="http://zakupki.gov.ru/oos/export/1">
  <ns2:fcsNotificationEFCancel>
    <purchaseNumber>0173200001426000002</purchaseNumber>
    <purchaseObjectInfo>Отменённая закупка</purchaseObjectInfo>
    <docPublishDate>2026-06-15T10:00:00+03:00</docPublishDate>
    <purchaseResponsible><responsibleOrg>
      <fullName>ГБУЗ Тест</fullName><INN>7701234568</INN><regionCode>77</regionCode>
    </responsibleOrg></purchaseResponsible>
    <notificationInfo><procedureInfo><collecting><endDate>2026-06-25T18:00:00+03:00</endDate></collecting></procedureInfo></notificationInfo>
    <lot><maxPrice>500000.00</maxPrice></lot>
  </ns2:fcsNotificationEFCancel>
</ns2:export>');

    $zipPath = makeEisArchive([$tmpXml]);

    $mapper = new EisNoticeMapper;
    $gateway = Mockery::mock(EisGateway::class);
    $adapter = new EisAdapter($gateway, $mapper);

    $raws = iterator_to_array($adapter->streamNotices($zipPath, LawType::Fz44));
    expect($raws)->toHaveCount(1);
    expect($raws[0]->rootElement)->toBe('fcsNotificationEFCancel');

    $normalized = $adapter->normalize($raws[0]);
    expect($normalized->sourceStatus)->toBe(TenderSourceStatus::Cancelled);

    @unlink($tmpXml);
    @unlink($zipPath);
});
