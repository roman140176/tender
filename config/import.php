<?php

return [
    'max_file_size_kb' => 5 * 1024,
    'max_rows' => 5000,
    'chunk_size' => 500,

    // нормализованный заголовок (lower, trim) => поле NormalizedTender
    'header_map' => [
        'название' => 'title',
        'наименование' => 'title',
        'описание' => 'description',
        'заказчик' => 'customer_name',
        'инн заказчика' => 'customer_inn',
        'инн' => 'customer_inn',
        'регион' => 'region',
        'цена' => 'price',
        'сумма' => 'price',
        'нмцк' => 'price',
        'валюта' => 'currency',
        'закон' => 'law_type',
        'тип процедуры' => 'procedure_type',
        'способ закупки' => 'procedure_type',
        'статус' => 'source_status',
        'дата публикации' => 'published_at',
        'дедлайн' => 'deadline_at',
        'срок подачи' => 'deadline_at',
        'ссылка' => 'source_url',
        'номер' => 'number',
        'окпд2' => 'okpd2',
    ],

    'law_type_map' => [
        '44-фз' => 'fz44', '44 фз' => 'fz44', 'fz44' => 'fz44',
        '223-фз' => 'fz223', '223 фз' => 'fz223', 'fz223' => 'fz223',
        'коммерческая' => 'commercial', 'коммерческие' => 'commercial', 'коммерческий' => 'commercial', 'commercial' => 'commercial',
    ],

    'status_map' => [
        'активна' => 'active', 'активная' => 'active', 'active' => 'active',
        'завершена' => 'completed', 'завершённая' => 'completed', 'completed' => 'completed',
        'отменена' => 'cancelled', 'отменённая' => 'cancelled', 'cancelled' => 'cancelled',
    ],

    'date_formats' => ['d.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d'],
];
