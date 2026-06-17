<?php

return [
    'endpoint' => env('EIS_ENDPOINT', 'https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP'),
    'token' => env('EIS_TOKEN'),
    'storage_disk' => env('EIS_STORAGE_DISK', 'local'),
    'backfill_days' => (int) env('EIS_BACKFILL_DAYS', 30),
    'chunk_archive_per_job' => true,
    'regions' => array_filter(explode(',', (string) env('EIS_REGIONS', '77,78'))),
    'subsystems' => ['PRIZ', 'RI223'],
    'subsystem_law_map' => ['PRIZ' => 'fz44', 'RI223' => 'fz223'],
    'notice_roots' => [
        'fz44' => ['fcsNotificationEF', 'fcsNotificationOK', 'fcsNotificationEP', 'fcsNotificationZK', 'fcsNotificationEFCancel'],
        'fz223' => ['purchaseNotice', 'purchaseNoticeCancel'],
    ],
    'source_url_template' => 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=%s',
    'fields' => [
        'fz44' => [
            'number' => 'purchaseNumber',
            'title' => 'purchaseObjectInfo',
            'publishedAt' => 'docPublishDate',
            'deadlineAt' => 'endDate',
            'customerName' => 'fullName',
            'customerInn' => 'INN',
            'region' => 'regionCode',
            'price' => 'maxPrice',
            'okpd2' => 'OKPDCode',
            'ktru' => 'KTRUCode',
        ],
        'fz223' => [
            'number' => 'purchaseNumber',
            'title' => 'purchaseObjectName',
            'publishedAt' => 'publicationDate',
            'deadlineAt' => 'submissionCloseDate',
            'customerName' => 'customerFullName',
            'customerInn' => 'customerInn',
            'region' => 'customerRegion',
            'price' => 'maxPrice',
            'okpd2' => 'okpd2Code',
            'ktru' => 'ktruCode',
        ],
    ],
    'schedule_cron' => '0 */4 * * *',
];
