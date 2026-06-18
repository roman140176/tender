<?php

it('eis config has correct endpoint default', function () {
    expect(config('eis.endpoint'))
        ->toBe('https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP');
});

it('eis config subsystem_law_map maps PRIZ to fz44 and RI223 to fz223', function () {
    expect(config('eis.subsystem_law_map.PRIZ'))->toBe('fz44');
    expect(config('eis.subsystem_law_map.RI223'))->toBe('fz223');
});

it('eis config notice_roots fz44 contains fcsNotificationEF and fcsNotificationEFCancel', function () {
    $roots = config('eis.notice_roots.fz44');
    expect($roots)->toContain('fcsNotificationEF');
    expect($roots)->toContain('fcsNotificationEFCancel');
});

it('eis config notice_roots fz223 contains purchaseNotice and purchaseNoticeCancel', function () {
    $roots = config('eis.notice_roots.fz223');
    expect($roots)->toContain('purchaseNotice');
    expect($roots)->toContain('purchaseNoticeCancel');
});

it('eis config fields fz44 has required keys', function () {
    $fields = config('eis.fields.fz44');
    foreach (['number', 'title', 'publishedAt', 'deadlineAt', 'customerName', 'customerInn', 'region', 'price', 'okpd2', 'ktru'] as $key) {
        expect($fields)->toHaveKey($key);
    }
});

it('eis config fields fz223 has required keys', function () {
    $fields = config('eis.fields.fz223');
    foreach (['number', 'title', 'publishedAt', 'deadlineAt', 'customerName', 'customerInn', 'region', 'price', 'okpd2', 'ktru'] as $key) {
        expect($fields)->toHaveKey($key);
    }
});

it('eis config source_url_template contains placeholder', function () {
    $tpl = config('eis.source_url_template');
    expect($tpl)->toContain('%s');
    expect(sprintf($tpl, '0173200001426000001'))
        ->toBe('https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=0173200001426000001');
});

it('eis config subsystems contains PRIZ and RI223', function () {
    expect(config('eis.subsystems'))->toContain('PRIZ');
    expect(config('eis.subsystems'))->toContain('RI223');
});

it('eis config schedule_cron has default value', function () {
    expect(config('eis.schedule_cron'))->toBe('0 */4 * * *');
});

it('eis config chunk_archive_per_job is true', function () {
    expect(config('eis.chunk_archive_per_job'))->toBeTrue();
});
