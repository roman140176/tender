<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\PackageRef;
use App\Adapters\Eis\SoapEisGateway;
use App\Enums\LawType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// NOTE: Имена SOAP-элементов (getDocsByOrgRegionRequest, архивные поля)
// требуют сверки с живой WSDL / Альбомом ТФФ ЕИС перед первым прод-запуском.

it('listPackages строит SOAP-запрос с токеном и возвращает PackageRef', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
        'eis.subsystem_law_map' => ['PRIZ' => 'fz44', 'RI223' => 'fz223'],
    ]);

    $archiveUrl = 'https://int44.zakupki.gov.ru/eis-integration/download/archiveUrl/some.zip';

    // Ответ возвращает один archiveUrl
    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>
             <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
               <S:Body>
                 <ns2:getDocsByOrgRegionResponse xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
                   <result><archiveUrl>'.$archiveUrl.'</archiveUrl></result>
                 </ns2:getDocsByOrgRegionResponse>
               </S:Body>
             </S:Envelope>',
            200,
            ['Content-Type' => 'text/xml; charset=utf-8']
        ),
    ]);

    $params = new CollectParams(
        since: CarbonImmutable::parse('2026-06-15', 'UTC'),
        until: CarbonImmutable::parse('2026-06-15', 'UTC'),
        regions: ['77'],
        subsystemTypes: ['PRIZ'],
    );

    $gateway = new SoapEisGateway;
    $packages = iterator_to_array($gateway->listPackages($params));

    expect($packages)->toHaveCount(1);
    expect($packages[0])->toBeInstanceOf(PackageRef::class);
    expect($packages[0]->archiveUrl)->toBe($archiveUrl);
    expect($packages[0]->region)->toBe('77');
    expect($packages[0]->subsystemType)->toBe('PRIZ');
    expect($packages[0]->date)->toBe('2026-06-15');
    expect($packages[0]->lawType)->toBe(LawType::Fz44);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('individualPerson_token', 'test-token')
            && str_contains((string) $request->url(), 'getDocsIP')
            && str_contains($request->body(), 'getDocsByOrgRegionRequest');
    });
});

it('listPackages пропускает дни без archiveUrl (пустой результат)', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
        'eis.subsystem_law_map' => ['PRIZ' => 'fz44', 'RI223' => 'fz223'],
    ]);

    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>
             <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
               <S:Body>
                 <ns2:getDocsByOrgRegionResponse xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
                   <result></result>
                 </ns2:getDocsByOrgRegionResponse>
               </S:Body>
             </S:Envelope>',
            200,
            ['Content-Type' => 'text/xml; charset=utf-8']
        ),
    ]);

    $params = new CollectParams(
        since: CarbonImmutable::parse('2026-06-15', 'UTC'),
        until: CarbonImmutable::parse('2026-06-15', 'UTC'),
        regions: ['77'],
        subsystemTypes: ['PRIZ'],
    );

    $gateway = new SoapEisGateway;
    $packages = iterator_to_array($gateway->listPackages($params));

    expect($packages)->toHaveCount(0);
});

it('fetchByReestrNumber возвращает XML-строку с токеном в заголовке', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
    ]);

    $rawXml = '<?xml version="1.0" encoding="UTF-8"?>
               <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
                 <S:Body>
                   <ns2:getDocsByReestrNumberResponse xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
                     <result><noticeXml>&lt;fcsNotificationEF&gt;&lt;/fcsNotificationEF&gt;</noticeXml></result>
                   </ns2:getDocsByReestrNumberResponse>
                 </S:Body>
               </S:Envelope>';

    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response($rawXml, 200, ['Content-Type' => 'text/xml; charset=utf-8']),
    ]);

    $gateway = new SoapEisGateway;
    $result = $gateway->fetchByReestrNumber('0173200001426000001');

    expect($result)->toBeString();
    expect($result)->not->toBeEmpty();

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('individualPerson_token', 'test-token')
            && str_contains($request->body(), 'getDocsByReestrNumberRequest')
            && str_contains($request->body(), '0173200001426000001');
    });
});

it('fetchByReestrNumber возвращает null при пустом ответе', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
    ]);

    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>
             <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
               <S:Body>
                 <ns2:getDocsByReestrNumberResponse xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
                   <result></result>
                 </ns2:getDocsByReestrNumberResponse>
               </S:Body>
             </S:Envelope>',
            200,
            ['Content-Type' => 'text/xml; charset=utf-8']
        ),
    ]);

    $gateway = new SoapEisGateway;
    $result = $gateway->fetchByReestrNumber('9999999999999999999');

    expect($result)->toBeNull();
});

it('listPackages бросает RequestException при HTTP 500', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
        'eis.subsystem_law_map' => ['PRIZ' => 'fz44', 'RI223' => 'fz223'],
    ]);

    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response('server error', 500),
    ]);

    $params = new CollectParams(
        since: CarbonImmutable::parse('2026-06-15', 'UTC'),
        until: CarbonImmutable::parse('2026-06-15', 'UTC'),
        regions: ['77'],
        subsystemTypes: ['PRIZ'],
    );

    $gateway = new SoapEisGateway;

    expect(fn () => iterator_to_array($gateway->listPackages($params)))
        ->toThrow(RequestException::class);
});

it('fetchByReestrNumber бросает RequestException при HTTP 500', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
    ]);

    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response('server error', 500),
    ]);

    $gateway = new SoapEisGateway;

    expect(fn () => $gateway->fetchByReestrNumber('0173200001426000001'))
        ->toThrow(RequestException::class);
});

it('extractArchiveUrls ловит namespaced (префиксный) archiveUrl', function () {
    config([
        'eis.token' => 'test-token',
        'eis.endpoint' => 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP',
        'eis.subsystem_law_map' => ['PRIZ' => 'fz44', 'RI223' => 'fz223'],
    ]);

    $archiveUrl = 'https://int44.zakupki.gov.ru/eis-integration/download/archiveUrl/a.zip';

    // archiveUrl с namespace-префиксом ns3 — реальный формат ответа ЕИС
    Http::fake([
        'int44.zakupki.gov.ru/*' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>
             <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
               <S:Body>
                 <ns2:getDocsByOrgRegionResponse xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
                   <result><ns3:archiveUrl xmlns:ns3="http://example/ns">'.$archiveUrl.'</ns3:archiveUrl></result>
                 </ns2:getDocsByOrgRegionResponse>
               </S:Body>
             </S:Envelope>',
            200,
            ['Content-Type' => 'text/xml; charset=utf-8']
        ),
    ]);

    $params = new CollectParams(
        since: CarbonImmutable::parse('2026-06-15', 'UTC'),
        until: CarbonImmutable::parse('2026-06-15', 'UTC'),
        regions: ['77'],
        subsystemTypes: ['PRIZ'],
    );

    $gateway = new SoapEisGateway;
    $packages = iterator_to_array($gateway->listPackages($params));

    expect($packages)->toHaveCount(1);
    expect($packages[0]->archiveUrl)->toBe($archiveUrl);
});

it('downloadArchive вызывает Http sink с токеном', function () {
    config(['eis.token' => 'test-token']);

    Http::fake([
        '*' => Http::response('zip-content', 200),
    ]);

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/eis-integration/download/some.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $dest = sys_get_temp_dir().'/eis_test_'.uniqid().'.zip';
    $gateway = new SoapEisGateway;
    $gateway->downloadArchive($ref, $dest);

    Http::assertSent(function (Request $request) use ($ref) {
        return $request->hasHeader('individualPerson_token', 'test-token')
            && (string) $request->url() === $ref->archiveUrl;
    });

    @unlink($dest);
});
