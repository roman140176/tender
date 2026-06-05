# A3: Пайплайн данных — Normalizer, Deduplicator, ручной импорт — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Загруженный админом CSV/XLSX/JSON-файл превращается в записи `tenders` (source=manual, приватные для компании) без дублей, с историей запусков и понятными ошибками строк.

**Architecture:** Сервисный слой (Canonicalizer → Normalizer → Deduplicator) без привязки к HTTP — A6 переиспользует его для ЕИС. Импорт синхронный в запросе (файлы ≤ 5 МБ / ≤ 5000 строк; XLSX — чанками), но сервис спроектирован под вызов из джобы. Дедуп-каскад по спеке §6.5 с advisory-lock'ом (заметка ревью A1: уровни 2–3 не защищены unique-констрейнтом).

**Tech Stack:** Laravel 13, maatwebsite/excel (XLSX/CSV chunk-чтение), PostgreSQL (advisory locks), Pest. `php` = 8.3 (дефолт).

**Спека:** §4.2 (пайплайн сбора), §6.3 (ручной импорт), §6.5 (дедупликация/content_hash), Р13 (приватность manual), Р15 (межисточниковый дедуп отложен).

**Ключевые инварианты (из ревью A1 — ОБЯЗАТЕЛЬНЫ):**
- `source=manual` ⇒ `owner_company_id` NOT NULL — энфорсится кодом (Deduplicator кидает исключение без owner для manual).
- Дедуп manual-закупок ищет дубли ТОЛЬКО среди manual-закупок этой же компании (приватность Р13).
- Insert/update `tenders` идут вне tenant-scope (Tender глобальная) — `owner_company_id` передаётся явно.
- `pg_advisory_xact_lock(hashtext(content_hash))` вокруг find-or-create (гонки параллельных воркеров A6).
- Дедуп по `source_url` — только при непустом значении.

**Решения плана (отступления, фиксируются в спеке при ревью):**
- Р-A3-1: формат «список URL» из §6.3 отложен до A6 (без адаптера URL не обогатить — карточка без названия бесполезна для скоринга).
- Р-A3-2: в `source_collect_runs` добавляется `company_id nullable` (для manual-ранов) — иначе историю импортов компании не отфильтровать.
- Р-A3-3: после импорта — hook-точка для A4 (комментарий в ImportService, диспатч MatchProfileJob добавит A4).

**Конвенции:** ветка `a3-import-pipeline`; после каждой задачи: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run build` (если трогался JS), коммит.

---

### Task 1: Зависимости, миграция company_id, конфиг маппинга

**Files:**
- Create: миграция `add_company_id_to_source_collect_runs`, `config/import.php`
- Modify: `composer.json` (maatwebsite/excel), `app/Models/SourceCollectRun.php`

- [ ] **Step 1: Ветка и пакет**

```bash
git checkout -b a3-import-pipeline
composer require maatwebsite/excel
```

(пакет требует ext-zip/gd — zip на хосте есть; если composer попросит gd — `php -m | grep -i gd`, при отсутствии BLOCKED с выводом.)

- [ ] **Step 2: Failing test** — `tests/Feature/Import/SourceCollectRunCompanyTest.php`:

```php
<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\Source;
use App\Models\SourceCollectRun;

it('ран сбора может принадлежать компании (manual-импорт)', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create(['code' => 'manual']);

    $run = SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => $company->id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Running,
        'started_at' => now(),
    ]);

    expect($run->refresh()->company_id)->toBe($company->id);
});
```

- [ ] **Step 3: Run → FAIL** (нет колонки)

- [ ] **Step 4: Миграция**

```php
public function up(): void
{
    Schema::table('source_collect_runs', function (Blueprint $table) {
        $table->foreignId('company_id')->nullable()->after('source_id')->index()->constrained()->cascadeOnDelete();
    });
}

public function down(): void
{
    Schema::table('source_collect_runs', function (Blueprint $table) {
        $table->dropConstrainedForeignId('company_id');
    });
}
```

(SourceCollectRun остаётся БЕЗ BelongsToCompany — глобальные раны имеют company_id NULL; история импортов фильтруется явным where.)

- [ ] **Step 5: `config/import.php`** — маппинг русских заголовков CSV/XLSX и ограничения:

```php
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
```

- [ ] **Step 6: Run → PASS**; полный прогон (87 passed), pint, phpstan.

- [ ] **Step 7: Commit** — `git commit -m "A3: maatwebsite/excel, company_id у ранов сбора, конфиг импорта"`

---

### Task 2: Canonicalizer и content_hash

**Files:**
- Create: `app/Services/Import/Canonicalizer.php`
- Test: `tests/Unit/Import/CanonicalizerTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Services\Import\Canonicalizer;

it('канонизирует строку: lower, trim, схлопывание пробелов, ё→е', function () {
    expect(Canonicalizer::text("  Поставка   МЕТАЛЛОКОНСТРУКЦИЙ\u{00A0} зелёных "))
        ->toBe('поставка металлоконструкций зеленых');
});

it('детерминированный content_hash независим от регистра и пробелов', function () {
    $a = Canonicalizer::contentHash('Поставка кранов', 'ООО «Ромашка»', '7701234567', '2026-06-01', '1500000.00');
    $b = Canonicalizer::contentHash('  поставка   кранов', 'ооо «ромашка»', '7701234567', '2026-06-01', '1500000.00');
    $c = Canonicalizer::contentHash('Поставка кранов', 'ООО «Ромашка»', '7701234567', '2026-06-01', '1500001.00');

    expect($a)->toBe($b)->and($a)->not->toBe($c)->and(strlen($a))->toBe(64);
});

it('null-поля хешируются стабильно', function () {
    $a = Canonicalizer::contentHash('X', null, null, null, null);
    $b = Canonicalizer::contentHash('X', null, null, null, null);
    expect($a)->toBe($b);
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Реализация**

```php
<?php

namespace App\Services\Import;

final class Canonicalizer
{
    /** lower + trim + NBSP→пробел + схлопывание пробелов + ё→е */
    public static function text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = mb_strtolower(str_replace("\u{00A0}", ' ', $value));
        $value = str_replace('ё', 'е', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Детерминированный хеш идентичности закупки (спека §6.5):
     * title | customer_name | customer_inn | published_date(Y-m-d) | price.
     */
    public static function contentHash(
        ?string $title,
        ?string $customerName,
        ?string $customerInn,
        ?string $publishedDate,
        ?string $price,
    ): string {
        return hash('sha256', implode('|', [
            self::text($title),
            self::text($customerName),
            self::text($customerInn),
            $publishedDate ?? '',
            $price ?? '',
        ]));
    }
}
```

- [ ] **Step 4: Run → PASS**, pint, phpstan. **Step 5: Commit** — `git commit -m "A3: Canonicalizer + детерминированный content_hash"`

---

### Task 3: NormalizedTender DTO и Normalizer

**Files:**
- Create: `app/Services/Import/NormalizedTender.php`, `app/Services/Import/Normalizer.php`, `app/Services/Import/NormalizationException.php`
- Test: `tests/Unit/Import/NormalizerTest.php`

- [ ] **Step 1: Failing test**

```php
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
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: DTO** — `app/Services/Import/NormalizedTender.php`:

```php
<?php

namespace App\Services\Import;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Carbon\CarbonImmutable;

final readonly class NormalizedTender
{
    /** @param list<string> $okpd2 */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $customerName,
        public ?string $customerInn,
        public ?string $region,
        public ?string $price,          // decimal-строка '1200000.50' или null
        public string $currency,
        public LawType $lawType,
        public ?string $procedureType,
        public TenderSourceStatus $sourceStatus,
        public ?CarbonImmutable $publishedAt,
        public ?CarbonImmutable $deadlineAt,
        public string $sourceUrl,       // '' если нет
        public ?string $number,
        public array $okpd2,
        public string $contentHash,
    ) {}
}
```

`app/Services/Import/NormalizationException.php` — `class NormalizationException extends \RuntimeException {}`.

- [ ] **Step 4: Normalizer** — `app/Services/Import/Normalizer.php`:

```php
<?php

namespace App\Services\Import;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Carbon\CarbonImmutable;

class Normalizer
{
    /**
     * @param  array<string, mixed>  $row  ключи — английские имена полей (header-маппинг применяет вызывающий)
     */
    public function normalize(array $row): NormalizedTender
    {
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            throw new NormalizationException('Пустое название закупки.');
        }

        $price = $this->parsePrice($row['price'] ?? null);
        $publishedAt = $this->parseDate($row['published_at'] ?? null);

        return new NormalizedTender(
            title: $title,
            description: $this->nullableString($row['description'] ?? null),
            customerName: $this->nullableString($row['customer_name'] ?? null),
            customerInn: $this->digitsOrNull($row['customer_inn'] ?? null),
            region: $this->nullableString($row['region'] ?? null),
            price: $price,
            currency: strtoupper($this->nullableString($row['currency'] ?? null) ?? 'RUB'),
            lawType: $this->mapEnum($row['law_type'] ?? null, config('import.law_type_map'), LawType::class, LawType::Other),
            procedureType: $this->nullableString($row['procedure_type'] ?? null),
            sourceStatus: $this->mapEnum($row['source_status'] ?? null, config('import.status_map'), TenderSourceStatus::class, TenderSourceStatus::Unknown),
            publishedAt: $publishedAt,
            deadlineAt: $this->parseDate($row['deadline_at'] ?? null),
            sourceUrl: trim((string) ($row['source_url'] ?? '')),
            number: $this->nullableString($row['number'] ?? null),
            okpd2: $this->parseList($row['okpd2'] ?? null),
            contentHash: Canonicalizer::contentHash(
                $title,
                $this->nullableString($row['customer_name'] ?? null),
                $this->digitsOrNull($row['customer_inn'] ?? null),
                $publishedAt?->format('Y-m-d'),
                $price,
            ),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function digitsOrNull(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));

        return $digits === '' ? null : $digits;
    }

    /** «1 200 000,50 ₽» → '1200000.50' */
    private function parsePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = str_replace([' ', "\u{00A0}", '₽', 'руб.', 'руб'], '', mb_strtolower((string) $value));
        $clean = str_replace(',', '.', $clean);

        if (! is_numeric($clean) || (float) $clean < 0) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        foreach (config('import.date_formats') as $format) {
            $parsed = CarbonImmutable::createFromFormat($format, $value);
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<string, string>  $map
     * @param  class-string<T>  $enumClass
     * @param  T  $default
     * @return T
     */
    private function mapEnum(mixed $value, array $map, string $enumClass, \BackedEnum $default): \BackedEnum
    {
        $key = Canonicalizer::text((string) ($value ?? ''));
        $mapped = $map[$key] ?? null;

        return $mapped !== null ? $enumClass::from($mapped) : $default;
    }

    /** @return list<string> */
    private function parseList(mixed $value): array
    {
        $parts = array_map('trim', explode(';', (string) ($value ?? '')));

        return array_values(array_filter($parts, fn (string $p) => $p !== ''));
    }
}
```

ВНИМАНИЕ к parseDate: `createFromFormat` без подавления ошибок в новых Carbon кидает исключение при несовпадении — оберни вызов в try/catch внутри цикла, если фактическое поведение Carbon в проекте такое (проверь тестом «вчера» — должен дать null, а не исключение).

- [ ] **Step 5: Run → PASS**, pint, phpstan. **Step 6: Commit** — `git commit -m "A3: Normalizer + NormalizedTender DTO"`

---

### Task 4: Deduplicator

**Files:**
- Create: `app/Services/Import/Deduplicator.php`, `app/Services/Import/DedupResult.php`
- Test: `tests/Feature/Import/DeduplicatorTest.php` (Feature — нужна БД)

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Company;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use App\Services\Import\Normalizer;

function manualSource(): Source
{
    return Source::factory()->create(['code' => 'manual-'.fake()->unique()->numerify('##')]);
}

function normalized(array $overrides = []): \App\Services\Import\NormalizedTender
{
    return app(Normalizer::class)->normalize(array_merge([
        'title' => 'Поставка кранов',
        'customer_name' => 'ООО «Ромашка»',
        'price' => '1500000',
        'published_at' => '01.06.2026',
        'source_url' => 'https://example.com/t/1',
    ], $overrides));
}

it('создаёт новую закупку с owner и manual-инвариантом', function () {
    $company = Company::factory()->create();
    $source = manualSource();

    $result = app(Deduplicator::class)->process(normalized(), $source->id, $company->id);

    expect($result->status)->toBe('created');
    $tender = Tender::sole();
    expect($tender->owner_company_id)->toBe($company->id)
        ->and($tender->external_id)->toHaveLength(64)
        ->and($tender->price)->toBe('1500000.00')
        ->and($tender->first_seen_at)->not->toBeNull();
});

it('manual без owner_company_id запрещён', function () {
    app(Deduplicator::class)->process(normalized(), manualSource()->id, null);
})->throws(InvalidArgumentException::class);

it('идентичная строка повторно — unchanged, last_seen_at обновлён', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(), $source->id, $company->id);
    $tender = Tender::sole();
    $tender->forceFill(['last_seen_at' => now()->subDay()])->save();

    $result = $dedup->process(normalized(), $source->id, $company->id);

    expect($result->status)->toBe('unchanged')
        ->and(Tender::count())->toBe(1)
        ->and(Tender::sole()->last_seen_at->isToday())->toBeTrue();
});

it('изменение цены и дедлайна — updated с diff в tender_changes', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(), $source->id, $company->id);
    // Та же закупка (тот же source_url), новые цена и дедлайн
    $result = $dedup->process(
        normalized(['price' => '1 700 000', 'deadline_at' => '25.06.2026']),
        $source->id,
        $company->id,
    );

    expect($result->status)->toBe('updated')->and(Tender::count())->toBe(1);

    $changes = Tender::sole()->changeLogs()->pluck('new_value', 'field');
    expect($changes)->toHaveKey('price')
        ->and($changes['price'])->toBe('1700000.00')
        ->and($changes)->toHaveKey('deadline_at');
});

it('одинаковый content_hash без url склеивается (3-й уровень каскада)', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(['source_url' => '']), $source->id, $company->id);
    $result = $dedup->process(normalized(['source_url' => '']), $source->id, $company->id);

    expect($result->status)->toBe('unchanged')->and(Tender::count())->toBe(1);
});

it('приватность: одинаковая manual-закупка двух компаний НЕ склеивается', function () {
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(), $source->id, Company::factory()->create()->id);
    $dedup->process(normalized(), $source->id, Company::factory()->create()->id);

    expect(Tender::count())->toBe(2);
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: DedupResult** — `app/Services/Import/DedupResult.php`:

```php
<?php

namespace App\Services\Import;

use App\Models\Tender;

final readonly class DedupResult
{
    public function __construct(
        public Tender $tender,
        public string $status, // created | updated | unchanged
    ) {}
}
```

- [ ] **Step 4: Deduplicator** — `app/Services/Import/Deduplicator.php`:

```php
<?php

namespace App\Services\Import;

use App\Models\Tender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Deduplicator
{
    /** Содержательные поля: их изменения трекаются в tender_changes (спека §4.2). */
    private const TRACKED_FIELDS = [
        'title', 'description', 'price', 'deadline_at', 'source_status',
        'customer_name', 'customer_inn', 'region',
    ];

    /**
     * Find-or-create по каскаду §6.5. Для manual owner обязателен (Р13).
     */
    public function process(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, ?int $collectRunId = null): DedupResult
    {
        $externalId = $n->number !== null && $n->number !== ''
            ? $n->number
            : $this->syntheticExternalId($n, $ownerCompanyId);

        if ($ownerCompanyId === null && $this->isManualSource($sourceId)) {
            throw new InvalidArgumentException('manual-источник требует owner_company_id (Р13).');
        }

        return DB::transaction(function () use ($n, $sourceId, $ownerCompanyId, $externalId, $collectRunId) {
            // Сериализация конкурентных воркеров по идентичности контента (ревью A1).
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$n->contentHash]);

            $existing = $this->findExisting($n, $sourceId, $ownerCompanyId, $externalId);

            if ($existing === null) {
                return new DedupResult($this->create($n, $sourceId, $ownerCompanyId, $externalId), 'created');
            }

            return $this->refresh($existing, $n, $collectRunId);
        });
    }

    private function isManualSource(int $sourceId): bool
    {
        return \App\Models\Source::query()->whereKey($sourceId)->value('connection_type') === 'manual';
    }

    private function findExisting(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, string $externalId): ?Tender
    {
        $scope = fn (Builder $q): Builder => $q
            ->where('source_id', $sourceId)
            ->when(
                $ownerCompanyId !== null,
                fn (Builder $q) => $q->where('owner_company_id', $ownerCompanyId),
                fn (Builder $q) => $q->whereNull('owner_company_id'),
            );

        // 1) source_id + external_id
        $found = $scope(Tender::query())->where('external_id', $externalId)->first();
        if ($found) {
            return $found;
        }

        // 2) source_url (только непустой)
        if ($n->sourceUrl !== '') {
            $found = $scope(Tender::query())->where('source_url', $n->sourceUrl)->first();
            if ($found) {
                return $found;
            }
        }

        // 3) content_hash
        return $scope(Tender::query())->where('content_hash', $n->contentHash)->first();
    }

    private function create(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, string $externalId): Tender
    {
        return Tender::create([
            'source_id' => $sourceId,
            'owner_company_id' => $ownerCompanyId,
            'external_id' => $externalId,
            'number' => $n->number,
            'source_url' => $n->sourceUrl,
            'title' => $n->title,
            'description' => $n->description,
            'customer_name' => $n->customerName,
            'customer_inn' => $n->customerInn,
            'region' => $n->region,
            'price' => $n->price,
            'currency' => $n->currency,
            'law_type' => $n->lawType,
            'procedure_type' => $n->procedureType,
            'source_status' => $n->sourceStatus,
            'published_at' => $n->publishedAt,
            'deadline_at' => $n->deadlineAt,
            'okpd2' => $n->okpd2,
            'content_hash' => $n->contentHash,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function refresh(Tender $tender, NormalizedTender $n, ?int $collectRunId): DedupResult
    {
        $incoming = [
            'title' => $n->title,
            'description' => $n->description,
            'price' => $n->price !== null ? number_format((float) $n->price, 2, '.', '') : null,
            'deadline_at' => $n->deadlineAt,
            'source_status' => $n->sourceStatus,
            'customer_name' => $n->customerName,
            'customer_inn' => $n->customerInn,
            'region' => $n->region,
        ];

        $changed = false;
        foreach (self::TRACKED_FIELDS as $field) {
            $new = $incoming[$field];
            $old = $tender->{$field};

            // нормализуем для сравнения (enum→value, Carbon→строка, decimal-строки)
            $oldCmp = $old instanceof \BackedEnum ? $old->value : ($old instanceof \DateTimeInterface ? $old->format('Y-m-d H:i:s') : $old);
            $newCmp = $new instanceof \BackedEnum ? $new->value : ($new instanceof \DateTimeInterface ? $new->format('Y-m-d H:i:s') : $new);

            if ($newCmp === null || (string) $oldCmp === (string) $newCmp) {
                continue; // null из источника не затирает данные; равные пропускаем
            }

            $tender->changeLogs()->create([
                'source_collect_run_id' => $collectRunId,
                'field' => $field,
                'old_value' => $oldCmp !== null ? (string) $oldCmp : null,
                'new_value' => (string) $newCmp,
            ]);
            $tender->{$field} = $new;
            $changed = true;
        }

        $tender->last_seen_at = now();
        $tender->save();

        return new DedupResult($tender, $changed ? 'updated' : 'unchanged');
    }

    /** Синтетический external_id для строк без номера (спека §6.3). */
    private function syntheticExternalId(NormalizedTender $n, ?int $ownerCompanyId): string
    {
        return hash('sha256', implode('|', [
            (string) $ownerCompanyId,
            Canonicalizer::text($n->title),
            Canonicalizer::text($n->customerName),
            $n->publishedAt?->format('Y-m-d') ?? '',
            $n->price ?? '',
        ]));
    }
}
```

ВНИМАНИЕ: сравнение price — Tender::price cast decimal:2 возвращает строку '1500000.00'; incoming price нормализован к тому же формату. Если тест «идентичная строка → unchanged» падает из-за формата сравнения — разберись (это главный риск задачи), не хакай тест.

- [ ] **Step 5: Run → PASS**, полный прогон, pint, phpstan. **Step 6: Commit** — `git commit -m "A3: Deduplicator (каскад §6.5, advisory lock, приватность manual)"`

---

### Task 5: ManualImportService (CSV/XLSX/JSON)

**Files:**
- Create: `app/Services/Import/ManualImportService.php`, `app/Services/Import/ImportRowCollector.php`
- Test: `tests/Feature/Import/ManualImportServiceTest.php` + фикстуры `tests/Fixtures/import.csv`, `tests/Fixtures/import.json`

- [ ] **Step 1: Фикстуры**

`tests/Fixtures/import.csv` (русские заголовки; вторая строка-дубль первой; третья — без названия):

```csv
Название;Заказчик;ИНН заказчика;Регион;Цена;Закон;Статус;Дата публикации;Дедлайн;Ссылка;Номер;ОКПД2
Поставка металлоконструкций;ГБУ Мосты;7701234567;Москва;1 200 000,50;44-ФЗ;Активна;01.06.2026;20.06.2026;https://example.com/t/1;0173200001;25.11
Поставка металлоконструкций;ГБУ Мосты;7701234567;Москва;1 200 000,50;44-ФЗ;Активна;01.06.2026;20.06.2026;https://example.com/t/1;0173200001;25.11
;Без названия;;;100;;;;;;;
Монтаж кранов;ООО Высота;7709876543;Санкт-Петербург;3 500 000;223-ФЗ;Активна;02.06.2026;25.06.2026;;;
```

(разделитель — `;`, как принято в русском Excel-CSV.)

`tests/Fixtures/import.json`:

```json
[
    {"title": "Ремонт фасада", "customer_name": "УК Дом", "price": "800000", "law_type": "commercial", "published_at": "2026-06-03"},
    {"title": "Ремонт кровли", "customer_name": "УК Дом", "price": "осмечивается", "published_at": "03.06.2026"}
]
```

- [ ] **Step 2: Failing test**

```php
<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Services\Import\ManualImportService;
use Database\Seeders\SourceSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(fn () => $this->seed(SourceSeeder::class));

it('импортирует CSV: новые, дубль внутри файла, ошибка строки', function () {
    $company = Company::factory()->create();
    $file = new UploadedFile(base_path('tests/Fixtures/import.csv'), 'import.csv', 'text/csv', test: true);

    $run = app(ManualImportService::class)->import($file, $company);

    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->trigger)->toBe(RunTrigger::Import)
        ->and($run->company_id)->toBe($company->id)
        ->and($run->fetched_count)->toBe(4)
        ->and($run->new_count)->toBe(2)      // дубль склеен, строка без названия — ошибка
        ->and($run->error_count)->toBe(1)
        ->and($run->log)->toContain('строка 4'); // нумерация с учётом заголовка

    expect(Tender::count())->toBe(2)
        ->and(Tender::whereNull('owner_company_id')->count())->toBe(0);
});

it('повторный импорт того же CSV не создаёт дублей', function () {
    $company = Company::factory()->create();
    $file = fn () => new UploadedFile(base_path('tests/Fixtures/import.csv'), 'import.csv', 'text/csv', test: true);

    app(ManualImportService::class)->import($file(), $company);
    $run2 = app(ManualImportService::class)->import($file(), $company);

    expect($run2->new_count)->toBe(0)->and(Tender::count())->toBe(2);
});

it('импортирует JSON с английскими ключами', function () {
    $company = Company::factory()->create();
    $file = new UploadedFile(base_path('tests/Fixtures/import.json'), 'import.json', 'application/json', test: true);

    $run = app(ManualImportService::class)->import($file, $company);

    expect($run->new_count)->toBe(2)->and($run->error_count)->toBe(0);
    expect(Tender::where('title', 'Ремонт кровли')->sole()->price)->toBeNull(); // кривая цена → null, не ошибка
});

it('импортирует XLSX', function () {
    $company = Company::factory()->create();

    // генерируем xlsx на лету из CSV-фикстуры
    $rows = array_map(fn ($line) => str_getcsv($line, ';'), file(base_path('tests/Fixtures/import.csv')));
    $export = new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    };
    \Maatwebsite\Excel\Facades\Excel::store($export, 'import-test.xlsx', 'local');
    $path = storage_path('app/private/import-test.xlsx');
    if (! file_exists($path)) {
        $path = storage_path('app/import-test.xlsx');
    }

    $run = app(ManualImportService::class)->import(
        new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true),
        $company,
    );

    expect($run->new_count)->toBe(2)->and(Tender::count())->toBe(2);
});

it('ран фиксируется даже при пустом файле', function () {
    $company = Company::factory()->create();
    $empty = UploadedFile::fake()->createWithContent('empty.csv', "Название;Цена\n");

    $run = app(ManualImportService::class)->import($empty, $company);

    expect($run->fetched_count)->toBe(0)
        ->and($run->status)->toBe(RunStatus::Ok)
        ->and(SourceCollectRun::count())->toBe(1);
});
```

- [ ] **Step 3: Run → FAIL**

- [ ] **Step 4: ImportRowCollector** (общий приёмник строк) — `app/Services/Import/ImportRowCollector.php`:

```php
<?php

namespace App\Services\Import;

use App\Models\Company;

class ImportRowCollector
{
    public int $fetched = 0;

    public int $new = 0;

    public int $updated = 0;

    public int $errors = 0;

    /** @var list<string> */
    public array $errorLines = [];

    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly Deduplicator $deduplicator,
        private readonly int $sourceId,
        private readonly Company $company,
        private readonly ?int $collectRunId,
    ) {}

    /** @param array<string, mixed> $row  английские ключи */
    public function accept(array $row, int $lineNumber): void
    {
        $this->fetched++;

        try {
            $normalized = $this->normalizer->normalize($row);
            $result = $this->deduplicator->process($normalized, $this->sourceId, $this->company->id, $this->collectRunId);

            match ($result->status) {
                'created' => $this->new++,
                'updated' => $this->updated++,
                default => null,
            };
        } catch (NormalizationException $e) {
            $this->errors++;
            $this->errorLines[] = "строка {$lineNumber}: {$e->getMessage()}";
        }
    }
}
```

- [ ] **Step 5: ManualImportService** — `app/Services/Import/ManualImportService.php`:

```php
<?php

namespace App\Services\Import;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class ManualImportService
{
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly Deduplicator $deduplicator,
    ) {}

    public function import(UploadedFile $file, Company $company): SourceCollectRun
    {
        $source = Source::query()->where('code', 'manual')->firstOrFail();

        $run = SourceCollectRun::create([
            'source_id' => $source->id,
            'company_id' => $company->id,
            'trigger' => RunTrigger::Import,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        $collector = new ImportRowCollector($this->normalizer, $this->deduplicator, $source->id, $company, $run->id);

        try {
            match ($this->detectFormat($file)) {
                'json' => $this->importJson($file, $collector),
                default => $this->importSpreadsheet($file, $collector), // csv и xlsx — единый путь maatwebsite
            };

            $run->update([
                'status' => RunStatus::Ok,
                'finished_at' => now(),
                'fetched_count' => $collector->fetched,
                'new_count' => $collector->new,
                'updated_count' => $collector->updated,
                'error_count' => $collector->errors,
                'log' => $collector->errorLines !== [] ? implode("\n", array_slice($collector->errorLines, 0, 100)) : null,
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => RunStatus::Error,
                'finished_at' => now(),
                'log' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }

        // A4: здесь диспатчится MatchProfileJob для активных профилей компании (trigger=import).

        return $run->refresh();
    }

    private function detectFormat(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension()) === 'json' ? 'json' : 'spreadsheet';
    }

    private function importJson(UploadedFile $file, ImportRowCollector $collector): void
    {
        $rows = json_decode((string) file_get_contents($file->getRealPath()), true);

        if (! is_array($rows)) {
            throw new NormalizationException('JSON должен содержать массив объектов закупок.');
        }

        if (count($rows) > config('import.max_rows')) {
            throw new NormalizationException('Слишком много строк: максимум '.config('import.max_rows').'.');
        }

        foreach (array_values($rows) as $i => $row) {
            $collector->accept(is_array($row) ? $row : [], $i + 1);
        }
    }

    private function importSpreadsheet(UploadedFile $file, ImportRowCollector $collector): void
    {
        $import = new SpreadsheetImport($collector, config('import.header_map'), config('import.max_rows'));
        Excel::import($import, $file);
    }
}
```

И `app/Services/Import/SpreadsheetImport.php` (maatwebsite-адаптер):

```php
<?php

namespace App\Services\Import;

use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class SpreadsheetImport implements OnEachRow, WithChunkReading, WithCustomCsvSettings, WithHeadingRow
{
    /** @param array<string, string> $headerMap */
    public function __construct(
        private readonly ImportRowCollector $collector,
        private readonly array $headerMap,
        private readonly int $maxRows,
    ) {}

    public function onRow(Row $row): void
    {
        if ($this->collector->fetched >= $this->maxRows) {
            throw new NormalizationException('Слишком много строк: максимум '.$this->maxRows.'.');
        }

        $raw = $row->toArray(null, true);
        $mapped = [];

        foreach ($raw as $header => $value) {
            $key = $this->headerMap[Canonicalizer::text((string) $header)] ?? null;
            if ($key !== null) {
                $mapped[$key] = $value;
            }
        }

        // полностью пустые строки пропускаем без счёта
        if (array_filter($mapped, fn ($v) => trim((string) $v) !== '') === []) {
            return;
        }

        $this->collector->accept($mapped, $row->getIndex());
    }

    public function chunkSize(): int
    {
        return (int) config('import.chunk_size');
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'input_encoding' => 'UTF-8'];
    }
}
```

ВНИМАНИЕ: WithHeadingRow по умолчанию «slugифицирует» заголовки (русские → транслит/слаги в зависимости от настроек). Если ключи $raw приходят не сырыми русскими заголовками — настрой `HeadingRowFormatter::none()` (в сервис-провайдере или локально через `WithHeadingRow` + formatter 'none' в config/excel.php после `php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"`). Разберись по факту и зафиксируй решение в отчёте — маппинг должен работать по русским заголовкам.

- [ ] **Step 6: Run → PASS**, полный прогон, pint, phpstan. **Step 7: Commit** — `git commit -m "A3: ManualImportService (CSV/XLSX/JSON, чанки, лог ошибок строк)"`

---

### Task 6: HTTP-слой импорта

**Files:**
- Create: `app/Http/Controllers/ImportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Import/ImportHttpTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\SourceCollectRun;
use App\Models\Tender;
use Database\Seeders\SourceSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(SourceSeeder::class));

it('страница импорта с историей ранов компании', function () {
    $admin = adminWithPlan();
    SourceCollectRun::create([
        'source_id' => \App\Models\Source::where('code', 'manual')->value('id'),
        'company_id' => $admin->company_id,
        'trigger' => 'import', 'status' => 'ok', 'started_at' => now(),
    ]);
    SourceCollectRun::create([ // чужой ран — не должен попасть
        'source_id' => \App\Models\Source::where('code', 'manual')->value('id'),
        'company_id' => \App\Models\Company::factory()->create()->id,
        'trigger' => 'import', 'status' => 'ok', 'started_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('imports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Imports/Index')
            ->has('runs', 1));
});

it('загрузка CSV создаёт закупки и ран', function () {
    $admin = adminWithPlan();
    $file = new UploadedFile(base_path('tests/Fixtures/import.csv'), 'закупки.csv', 'text/csv', test: true);

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => $file])
        ->assertRedirect(route('imports.index', absolute: false))
        ->assertSessionHas('success');

    expect(Tender::count())->toBe(2)
        ->and(SourceCollectRun::where('company_id', $admin->company_id)->count())->toBe(1);
});

it('отклоняет неподдерживаемый формат и большой файл', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertSessionHasErrors('file');

    $this->actingAs($admin)
        ->post(route('imports.store'), ['file' => UploadedFile::fake()->create('big.csv', 6 * 1024)])
        ->assertSessionHasErrors('file');
});

it('менеджеру запрещено', function () {
    $admin = adminWithPlan();
    $manager = \App\Models\User::factory()->for($admin->company)->create(['role' => \App\Enums\UserRole::Manager]);

    $this->actingAs($manager)->get(route('imports.index'))->assertForbidden();
});

it('шаблон CSV скачивается', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get(route('imports.template'))
        ->assertOk()
        ->assertDownload('tender-import-template.csv');
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Controller** — `app/Http/Controllers/ImportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Services\Import\ManualImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Imports/Index', [
            'runs' => SourceCollectRun::query()
                ->where('company_id', $request->user()->company_id)
                ->latest('started_at')
                ->limit(20)
                ->get(['id', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'updated_count', 'error_count', 'log']),
            'maxFileSizeKb' => (int) config('import.max_file_size_kb'),
            'maxRows' => (int) config('import.max_rows'),
        ]);
    }

    public function store(Request $request, ManualImportService $service): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'mimes:csv,txt,xlsx,json',
                'max:'.config('import.max_file_size_kb'),
            ],
        ], [
            'file.mimes' => 'Поддерживаются файлы CSV, XLSX и JSON.',
            'file.max' => 'Файл больше '.round(config('import.max_file_size_kb') / 1024).' МБ.',
        ]);

        $run = $service->import($request->file('file'), $request->user()->company);

        $message = "Импорт завершён: новых {$run->new_count}, обновлено {$run->updated_count}, ошибок {$run->error_count}.";

        return redirect()->route('imports.index')->with($run->error_count > 0 ? 'error' : 'success', $message);
    }

    public function template(): StreamedResponse
    {
        $headers = "Название;Описание;Заказчик;ИНН заказчика;Регион;Цена;Валюта;Закон;Тип процедуры;Статус;Дата публикации;Дедлайн;Ссылка;Номер;ОКПД2\n";
        $example = "Поставка металлоконструкций;По чертежам КМД;ГБУ Мосты;7701234567;Москва;1200000,50;RUB;44-ФЗ;Аукцион;Активна;01.06.2026;20.06.2026;https://zakupki.gov.ru/...;0173200001;25.11\n";

        return response()->streamDownload(
            fn () => print("\u{FEFF}".$headers.$example), // BOM — чтобы Excel открыл UTF-8 корректно
            'tender-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
```

- [ ] **Step 4: Routes** — в группу `can:manage-company`:

```php
Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
```

(template-маршрут объявить ДО `imports/{...}`-подобных, конфликтов нет — но проверь порядок с resource-маршрутами profiles.)

- [ ] **Step 5: Run → PASS**, полный прогон, pint, phpstan. **Step 6: Commit** — `git commit -m "A3: HTTP-слой импорта (загрузка, история, шаблон)"`

---

### Task 7: UI импорта

**Files:**
- Create: `resources/js/Pages/Imports/Index.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (пункт «Импорт» после «Источники»)

- [ ] **Step 1: Страница** — `resources/js/Pages/Imports/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    runs: Array,
    maxFileSizeKb: Number,
    maxRows: Number,
});

const form = useForm({ file: null });

const submit = () => {
    form.post(route('imports.store'), {
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
};

const STATUS = { ok: 'Готово', error: 'Ошибка', running: 'Выполняется' };

const formatDate = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
</script>

<template>
    <Head title="Импорт закупок" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Импорт закупок</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900">Загрузка файла</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        CSV (разделитель «;»), XLSX или JSON, до {{ Math.round(maxFileSizeKb / 1024) }} МБ
                        и {{ maxRows }} строк.
                        <a :href="route('imports.template')" class="text-indigo-600 hover:underline">Скачать шаблон CSV</a>.
                    </p>

                    <form class="mt-4 flex items-center gap-3" @submit.prevent="submit">
                        <input
                            type="file"
                            accept=".csv,.xlsx,.json"
                            class="block text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
                            @change="form.file = $event.target.files[0]"
                        />
                        <PrimaryButton :disabled="form.processing || !form.file">
                            {{ form.processing ? 'Импортируем…' : 'Импортировать' }}
                        </PrimaryButton>
                    </form>
                    <InputError class="mt-2" :message="form.errors.file" />
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg">
                    <h3 class="p-6 pb-0 text-lg font-medium text-gray-900">История импортов</h3>

                    <p v-if="!runs.length" class="p-6 text-gray-500">Импортов ещё не было.</p>

                    <table v-else class="mt-4 min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Когда</th>
                                <th class="px-6 py-3">Статус</th>
                                <th class="px-6 py-3">Строк</th>
                                <th class="px-6 py-3">Новых</th>
                                <th class="px-6 py-3">Обновлено</th>
                                <th class="px-6 py-3">Ошибок</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="run in runs" :key="run.id" class="align-top">
                                <td class="px-6 py-3 whitespace-nowrap">{{ formatDate(run.started_at) }}</td>
                                <td class="px-6 py-3">
                                    <span
                                        :class="{
                                            'bg-green-100 text-green-800': run.status === 'ok',
                                            'bg-red-100 text-red-800': run.status === 'error',
                                            'bg-yellow-100 text-yellow-800': run.status === 'running',
                                        }"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                    >
                                        {{ STATUS[run.status] ?? run.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-3">{{ run.fetched_count }}</td>
                                <td class="px-6 py-3 font-medium text-green-700">{{ run.new_count }}</td>
                                <td class="px-6 py-3">{{ run.updated_count }}</td>
                                <td class="px-6 py-3">
                                    <span :class="run.error_count ? 'font-medium text-red-700' : ''">{{ run.error_count }}</span>
                                    <details v-if="run.log" class="mt-1 text-xs text-gray-500">
                                        <summary class="cursor-pointer">подробности</summary>
                                        <pre class="mt-1 whitespace-pre-wrap">{{ run.log }}</pre>
                                    </details>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Навигация** — пункт «Импорт» (NavLink + ResponsiveNavLink, `route('imports.index')`, active `imports.*`) внутри блока `manageCompany`, после «Источники».

- [ ] **Step 3: Build + полный прогон + Commit** — `git commit -m "A3: UI импорта (загрузка, история, шаблон)"`

---

### Task 8: Финальная проверка A3

- [ ] **Step 1: Полный прогон** — `php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && npm run build`

- [ ] **Step 2: Ручной smoke** — `php artisan serve`: войти админом → Импорт → скачать шаблон → загрузить его же → увидеть «новых 1»; загрузить повторно → «новых 0»; проверить историю.

- [ ] **Step 3: Спека** — добавить в §6.3: «формат "список URL" отложен до A6 (Р-A3-1); manual-раны фиксируются в source_collect_runs с company_id (Р-A3-2)». Роадмап — A3 «выполнен».

- [ ] **Step 4: Финальное ревью ветки** (контроллер диспатчит) → merge по finishing-a-development-branch.

---

## Definition of Done (A3)

- Загрузка CSV/XLSX/JSON через UI создаёт `tenders` с `source=manual`, `owner_company_id` компании-загрузчика; строки без названия попадают в лог ошибок с номерами, не валя импорт.
- Повторная загрузка того же файла не создаёт дублей (каскад: number → source_url → content_hash); изменённые цена/дедлайн дают `updated` + diff в `tender_changes`.
- Приватность: manual-закупки разных компаний не склеиваются; история импортов показывает только раны своей компании.
- Шаблон CSV скачивается (UTF-8 BOM, русские заголовки); лимиты размера/строк с русскими ошибками; менеджеру — 403.
- Все тесты, pint, phpstan L6, build — зелёные.
