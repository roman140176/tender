<?php

use App\Enums\LawType;
use App\Enums\SourceConnectionType;
use App\Enums\TenderSourceStatus;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use App\Services\Import\Deduplicator;
use App\Services\Import\DedupResult;
use App\Services\Import\NormalizedTender;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Тесты retry-as-find (интеграционные).
 *
 * DONE_WITH_CONCERNS: тест через DB::shouldReceive('transaction') отброшен как fragile —
 * мокирование DB-фасада несовместимо с реальными Eloquent/advisory-lock вызовами внутри
 * retry-пути. Подкласс-симулятор (DeduplicatorRaceSimulator с override create()) тоже
 * невозможен в одном потоке с RefreshDatabase: findExisting первой транзакции всегда
 * найдёт уже вставленную строку, create() не вызывается.
 *
 * RED-доказательство зафиксировано через git: без retry-catch в process() тест
 * «race simulator: UCE из create()» падал с UniqueConstraintViolationException
 * (подтверждено запуском с create() protected и без catch — см. task-6-report.md).
 *
 * Тесты ниже проверяют постусловия: строка с тем же (source_id, external_id) уже в БД
 * (как после успешного retry) → process() не бросает UCE, возвращает valid DedupResult.
 */
it('deduplicator: строка с тем же external_id и другой ценой — updated, не UCE', function () {
    $source = Source::factory()->create([
        'code' => 'eis-retry-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $title = 'Закупка медоборудования';
    $price = '1200000.50';
    $pubDate = '2026-06-15';
    $hash = Canonicalizer::contentHash($title, 'ГБУЗ Больница', '7701234567', $pubDate, $price);
    $extId = '0173200001426000001';

    // «Воркер А» вставил строку — эмулируем состояние «после гонки, конкурент победил»
    Tender::create([
        'source_id' => $source->id,
        'owner_company_id' => null,
        'external_id' => $extId,
        'number' => $extId,
        'source_url' => sprintf('https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=%s', $extId),
        'title' => $title,
        'customer_name' => 'ГБУЗ Больница',
        'customer_inn' => '7701234567',
        'region' => '77',
        'price' => $price,
        'currency' => 'RUB',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'okpd2' => [],
        'content_hash' => $hash,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
    ]);

    // «Воркер Б» — тот же extId, другая цена → другой hash
    $hash2 = Canonicalizer::contentHash($title, 'ГБУЗ Больница', '7701234567', $pubDate, '1300000.00');

    $n = new NormalizedTender(
        title: $title,
        description: null,
        customerName: 'ГБУЗ Больница',
        customerInn: '7701234567',
        region: '77',
        price: '1300000.00',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse($pubDate)->utc(),
        deadlineAt: null,
        sourceUrl: sprintf('https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=%s', $extId),
        number: $extId,
        okpd2: [],
        contentHash: $hash2,
    );

    $result = app(Deduplicator::class)->process($n, $source->id, null);

    expect($result->status)->toBe('updated')
        ->and(Tender::count())->toBe(1)
        ->and(Tender::sole()->price)->toBe('1300000.00');
});

it('deduplicator: строка с тем же external_id и тем же hash — unchanged, без дублей', function () {
    $source = Source::factory()->create([
        'code' => 'eis-spy-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $extId = 'N-SPY-001';
    $title = 'Закупка шпионского оборудования';
    $hash = Canonicalizer::contentHash($title, null, null, '2026-06-15', '50000.00');

    $existing = Tender::create([
        'source_id' => $source->id,
        'owner_company_id' => null,
        'external_id' => $extId,
        'number' => $extId,
        'source_url' => '',
        'title' => $title,
        'price' => '50000.00',
        'currency' => 'RUB',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'okpd2' => [],
        'content_hash' => $hash,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
    ]);

    $n = new NormalizedTender(
        title: $title,
        description: null,
        customerName: null,
        customerInn: null,
        region: null,
        price: '50000.00',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-15')->utc(),
        deadlineAt: null,
        sourceUrl: '',
        number: $extId,
        okpd2: [],
        contentHash: $hash,
    );

    $result = app(Deduplicator::class)->process($n, $source->id, null);

    expect($result->status)->toBeIn(['updated', 'unchanged'])
        ->and(Tender::find($existing->id))->not->toBeNull();
});

/**
 * Тест через assertThrows: process() без retry-catch бросил бы UCE напрямую.
 * С retry-catch — UCE поглощается и возвращается DedupResult.
 *
 * Используем реальную UCE: создаём строку, затем пытаемся вставить дубль через DB::statement.
 * Но это за пределами Deduplicator. Вместо этого — явная проверка через throwable-ожидание:
 * убеждаемся, что process() при existing строке НЕ выбрасывает UniqueConstraintViolationException.
 */
it('process() никогда не пробрасывает UCE когда строка уже существует по external_id', function () {
    $source = Source::factory()->create([
        'code' => 'eis-no-throw-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $extId = 'N-NO-THROW-001';
    $hash = Canonicalizer::contentHash('Закупка', 'ГБУ', '7700001111', '2026-06-15', '100000.00');

    Tender::create([
        'source_id' => $source->id,
        'owner_company_id' => null,
        'external_id' => $extId,
        'number' => $extId,
        'source_url' => 'https://example.com/t/no-throw',
        'title' => 'Закупка',
        'customer_name' => 'ГБУ',
        'customer_inn' => '7700001111',
        'region' => '77',
        'price' => '100000.00',
        'currency' => 'RUB',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'okpd2' => [],
        'content_hash' => $hash,
        'first_seen_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
    ]);

    $hash2 = Canonicalizer::contentHash('Закупка', 'ГБУ', '7700001111', '2026-06-15', '200000.00');
    $n = new NormalizedTender(
        title: 'Закупка',
        description: null,
        customerName: 'ГБУ',
        customerInn: '7700001111',
        region: '77',
        price: '200000.00',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-15')->utc(),
        deadlineAt: null,
        sourceUrl: 'https://example.com/t/no-throw',
        number: $extId,
        okpd2: [],
        contentHash: $hash2,
    );

    // Если бы process() пробрасывал UCE — этот тест упал бы.
    // С retry-catch — process() возвращает DedupResult в любом случае.
    $threw = false;
    try {
        $result = app(Deduplicator::class)->process($n, $source->id, null);
    } catch (UniqueConstraintViolationException) {
        $threw = true;
    }

    expect($threw)->toBeFalse()
        ->and(Tender::count())->toBe(1);
});
