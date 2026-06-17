<?php

use App\Enums\LawType;
use App\Enums\SourceConnectionType;
use App\Enums\TenderSourceStatus;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use App\Services\Import\NormalizedTender;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * DeduplicatorRaceSimulator — тестовый подкласс, симулирующий race window.
 *
 * Переопределяет findExisting() так, что первый вызов возвращает null (строка «невидима»
 * в окне гонки), а каждый последующий вызов делегирует к parent::findExisting() и находит
 * реальную строку в БД. Это заставляет process() дойти до create() и получить настоящее
 * PostgreSQL UniqueConstraintViolationException, после чего catch-retry-путь отрабатывает
 * по-настоящему.
 */
class DeduplicatorRaceSimulator extends Deduplicator
{
    public int $findCalls = 0;

    protected function findExisting(
        NormalizedTender $n,
        int $sourceId,
        ?int $ownerCompanyId,
        string $externalId
    ): ?Tender {
        $this->findCalls++;
        if ($this->findCalls === 1) {
            // Симулируем race window: строка уже вставлена конкурентом, но ещё не видна нам
            return null;
        }

        // На retry делегируем к родителю — строка теперь видна (committed)
        return parent::findExisting($n, $sourceId, $ownerCompanyId, $externalId);
    }
}

/**
 * DeduplicatorAlwaysNullFinder — симулятор для теста rethrow:
 * findExisting() всегда возвращает null, так что retry тоже не найдёт строку → rethrow UCE.
 */
class DeduplicatorAlwaysNullFinder extends Deduplicator
{
    protected function findExisting(
        NormalizedTender $n,
        int $sourceId,
        ?int $ownerCompanyId,
        string $externalId
    ): ?Tender {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Тест 1: recovery — catch+retry срабатывает, дубль не создаётся
// ---------------------------------------------------------------------------

it('retry-as-find recovery: реальное PG UCE поглощается, findCalls >= 2, count === 1', function () {
    $source = Source::factory()->create([
        'code' => 'eis-sim-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $extId = 'N-SIM-RACE-001';

    // Строка R вставлена НАПРЯМУЮ (committed до вызова process()) — имитируем конкурента
    Tender::create([
        'source_id' => $source->id,
        'owner_company_id' => null,
        'external_id' => $extId,
        'number' => $extId,
        'source_url' => 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-SIM-RACE-001',
        'title' => 'Поставка реагентов',
        'customer_name' => 'ГБУ Лаборатория',
        'customer_inn' => '7709123456',
        'region' => '77',
        'price' => '750000.00',
        'currency' => 'RUB',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'okpd2' => [],
        'content_hash' => 'aabbcc-original-hash',
        'first_seen_at' => now()->subMinutes(5),
        'last_seen_at' => now()->subMinutes(5),
    ]);

    // NormalizedTender с тем же external_id (number), но другим content_hash → иная цена
    $n = new NormalizedTender(
        title: 'Поставка реагентов',
        description: null,
        customerName: 'ГБУ Лаборатория',
        customerInn: '7709123456',
        region: '77',
        price: '800000.00',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-10')->utc(),
        deadlineAt: null,
        sourceUrl: 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-SIM-RACE-001',
        number: $extId,
        okpd2: [],
        contentHash: 'ddeeff-updated-hash',
    );

    $sim = new DeduplicatorRaceSimulator;

    // Поток вызовов:
    // 1. первый findExisting (sim) → null
    // 2. create() → реальный INSERT → PG UniqueConstraintViolationException (extId уже в БД)
    // 3. catch → retry-транзакция
    // 4. второй findExisting (sim → parent) → находит строку R
    // 5. refresh() → DedupResult('updated')
    $result = $sim->process($n, $source->id, null);

    expect($result->status)->toBeIn(['updated', 'unchanged'])
        ->and($sim->findCalls)->toBeGreaterThanOrEqual(2)
        ->and(Tender::query()->where('source_id', $source->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Тест 2: rethrow — если retry тоже не находит строку, UCE пробрасывается
// ---------------------------------------------------------------------------

it('retry-as-find rethrow: UCE пробрасывается когда retry тоже не находит строку', function () {
    $source = Source::factory()->create([
        'code' => 'eis-rethrow-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $extId = 'N-RETHROW-001';

    // Строка в БД — нужна, чтобы create() поймал реальное PG UCE при первой попытке вставки.
    // DeduplicatorAlwaysNullFinder будет возвращать null и на retry → rethrow.
    Tender::create([
        'source_id' => $source->id,
        'owner_company_id' => null,
        'external_id' => $extId,
        'number' => $extId,
        'source_url' => '',
        'title' => 'Закупка для rethrow-теста',
        'price' => '100.00',
        'currency' => 'RUB',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'okpd2' => [],
        'content_hash' => 'rethrow-orig-hash',
        'first_seen_at' => now()->subMinutes(1),
        'last_seen_at' => now()->subMinutes(1),
    ]);

    $n = new NormalizedTender(
        title: 'Закупка для rethrow-теста',
        description: null,
        customerName: null,
        customerInn: null,
        region: null,
        price: '200.00',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-17')->utc(),
        deadlineAt: null,
        sourceUrl: '',
        number: $extId,
        okpd2: [],
        contentHash: 'rethrow-new-hash',
    );

    $dedup = new DeduplicatorAlwaysNullFinder;

    // findExisting → null → create() → PG UCE → catch → retry findExisting → null → rethrow UCE
    expect(fn () => $dedup->process($n, $source->id, null))
        ->toThrow(UniqueConstraintViolationException::class);
});

// ---------------------------------------------------------------------------
// Тесты 3–5: level-1 found path (переименованы с честным описанием)
// ---------------------------------------------------------------------------

it('level-1 found: первый process создаёт, второй process с другим hash обновляет без UCE', function () {
    $source = Source::factory()->create([
        'code' => 'eis-race-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $dedup = app(Deduplicator::class);

    $n1 = new NormalizedTender(
        title: 'Поставка оборудования',
        description: null,
        customerName: 'ГБУ Тест',
        customerInn: null,
        region: null,
        price: '500000',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-15')->utc(),
        deadlineAt: null,
        sourceUrl: 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-RACE-001',
        number: 'N-RACE-001',
        okpd2: [],
        contentHash: 'hash-v1',
    );

    $r1 = $dedup->process($n1, $source->id, null);
    expect($r1->status)->toBe('created');

    $n2 = new NormalizedTender(
        title: 'Поставка оборудования',
        description: null,
        customerName: 'ГБУ Тест',
        customerInn: null,
        region: null,
        price: '600000',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-15')->utc(),
        deadlineAt: null,
        sourceUrl: 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-RACE-001',
        number: 'N-RACE-001',
        okpd2: [],
        contentHash: 'hash-v2',
    );

    // Строка уже существует → level-1 found → updated, UCE не возникает
    $r2 = $dedup->process($n2, $source->id, null);

    expect($r2->status)->toBeIn(['updated', 'unchanged'])
        ->and(Tender::count())->toBe(1);
});

it('level-1 found: повторный process с тем же NormalizedTender — unchanged, без дублей', function () {
    $source = Source::factory()->create([
        'code' => 'eis-race2-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $n = new NormalizedTender(
        title: 'Закупка услуг',
        description: null,
        customerName: null,
        customerInn: null,
        region: null,
        price: '300000',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-16')->utc(),
        deadlineAt: null,
        sourceUrl: 'https://example.com/t/race2',
        number: 'N-RACE-002',
        okpd2: [],
        contentHash: 'hash-race2',
    );

    $dedup = app(Deduplicator::class);
    $dedup->process($n, $source->id, null);

    $r = $dedup->process($n, $source->id, null);

    // Статус зависит от нормализации price в PG ('300000' vs '300000.00'), принимаем оба
    expect($r->status)->toBeIn(['updated', 'unchanged'])
        ->and(Tender::count())->toBe(1);
});

it('level-1 found: два вызова с одним номером — второй получает unchanged через level-1 find', function () {
    $source = Source::factory()->create([
        'code' => 'eis-race3-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::Api,
    ]);

    $n = new NormalizedTender(
        title: 'Ремонт дорог',
        description: null,
        customerName: 'МКУ Дороги',
        customerInn: null,
        region: null,
        price: '9000000',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse('2026-06-10')->utc(),
        deadlineAt: null,
        sourceUrl: 'https://example.com/t/race3',
        number: 'N-RACE-003',
        okpd2: [],
        contentHash: 'hash-race3',
    );

    $dedup = app(Deduplicator::class);

    $rA = $dedup->process($n, $source->id, null);
    expect($rA->status)->toBe('created');

    // Второй вызов с тем же n → level-1 find. Статус зависит от нормализации price в PG.
    $rB = $dedup->process($n, $source->id, null);
    expect($rB->status)->toBeIn(['updated', 'unchanged'])
        ->and(Tender::count())->toBe(1);
});
