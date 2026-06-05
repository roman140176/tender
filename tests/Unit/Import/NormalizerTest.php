<?php

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\NormalizationException;
use App\Services\Import\Normalizer;

it('нормализует полную строку с русскими значениями', function () {
    $n = app(Normalizer::class)->normalize([
        'title' => '  Поставка металлоконструкций ',
        'description' => 'По чертежам КМД',
        'customer_name' => 'ГБУ «Мосты»',
        'customer_inn' => '7701234567',
        'region' => 'Москва',
        'price' => '1 200 000,50 ₽',
        'law_type' => '44-ФЗ',
        'source_status' => 'Активна',
        'published_at' => '01.06.2026',
        'deadline_at' => '2026-06-20 10:00:00',
        'source_url' => 'https://example.com/t/1',
        'number' => '0173200001426000001',
        'okpd2' => '25.11; 25.11.23',
    ]);

    expect($n->title)->toBe('Поставка металлоконструкций')
        ->and($n->price)->toBe('1200000.50')
        ->and($n->lawType)->toBe(LawType::Fz44)
        ->and($n->sourceStatus)->toBe(TenderSourceStatus::Active)
        ->and($n->publishedAt?->format('Y-m-d'))->toBe('2026-06-01')
        ->and($n->publishedAt?->format('H:i:s'))->toBe('00:00:00')
        ->and($n->deadlineAt?->format('Y-m-d H:i'))->toBe('2026-06-20 10:00')
        ->and($n->okpd2)->toBe(['25.11', '25.11.23'])
        ->and($n->contentHash)->toHaveLength(64);
});

it('минимальная строка: только название', function () {
    $n = app(Normalizer::class)->normalize(['title' => 'Закупка кранов']);

    expect($n->title)->toBe('Закупка кранов')
        ->and($n->price)->toBeNull()
        ->and($n->lawType)->toBe(LawType::Other)
        ->and($n->sourceStatus)->toBe(TenderSourceStatus::Unknown)
        ->and($n->sourceUrl)->toBe('');
});

it('строка без названия отклоняется', function () {
    app(Normalizer::class)->normalize(['description' => 'без названия']);
})->throws(NormalizationException::class);

it('кривые цена и дата не валят строку, а становятся null', function () {
    $n = app(Normalizer::class)->normalize([
        'title' => 'X',
        'price' => 'договорная',
        'published_at' => 'вчера',
    ]);

    expect($n->price)->toBeNull()->and($n->publishedAt)->toBeNull();
});

it('неизвестный закон и статус маппятся в other/unknown', function () {
    $n = app(Normalizer::class)->normalize(['title' => 'X', 'law_type' => '94-ФЗ', 'source_status' => 'черновик']);
    expect($n->lawType)->toBe(LawType::Other)->and($n->sourceStatus)->toBe(TenderSourceStatus::Unknown);
});
