<?php

use function Tests\Support\makeEisArchive;

it('makeEisArchive возвращает читаемый zip с двумя xml-файлами', function () {
    $fz44 = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $fz223 = base_path('tests/Fixtures/eis/fz223_notice.xml');

    $zipPath = makeEisArchive([$fz44, $fz223]);

    expect(file_exists($zipPath))->toBeTrue();

    $zip = new ZipArchive;
    $opened = $zip->open($zipPath);
    expect($opened)->toBe(true);
    expect($zip->numFiles)->toBe(2);

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    expect($names)->toContain('fz44_notice.xml')
        ->and($names)->toContain('fz223_notice.xml');

    $zip->close();
    @unlink($zipPath);
});

it('фикстура fz44 содержит purchaseNumber', function () {
    $xml = simplexml_load_file(base_path('tests/Fixtures/eis/fz44_notice.xml'));
    $ns2 = $xml->children('http://zakupki.gov.ru/oos/export/1');
    $notif = $ns2->fcsNotificationEF;
    expect((string) $notif->purchaseNumber)->toBe('0173200001426000001');
});

it('фикстура fz223 содержит purchaseNumber', function () {
    $xml = simplexml_load_file(base_path('tests/Fixtures/eis/fz223_notice.xml'));
    $ns3 = $xml->children('http://zakupki.gov.ru/223fz/export/1');
    $notif = $ns3->purchaseNotice;
    expect((string) $notif->purchaseNumber)->toBe('32212345678');
});
