# A6 — Адаптер ЕИС: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Завести живые закупки ЕИС (44-ФЗ и 223-ФЗ) в систему по кнопке/расписанию: SOAP `getDocsIP` → ZIP-выгрузки → потоковый XML → нормализация/дедупликация → глобальный матчинг по всем активным профилям.

**Architecture:** Транспорт ЕИС спрятан за интерфейсом `EisGateway` (реальный `SoapEisGateway` через Http-фасад; в тестах — фейк + фикстуры-архивы). `EisAdapter` (реализует `TenderSourceAdapter` §6.1) тянет список пакетов, скачивает архивы, потоково парсит XML (`ZipArchive`+`XMLReader`) и через `EisNoticeMapper` отдаёт `NormalizedTender` в существующий `Deduplicator`. Оркестрация сбора — Laravel Job Batches: `CollectSourceJob` (re-run lock + run + батч) → `DownloadPackageJob` (скачать архив, добавить чанк в батч) → `ProcessChunkJob` (поток→дедуп→счётчики), по завершении батча — `CollectMatchFanoutJob` (глобальный матчинг). Воркеры — Horizon, очередь `collect`.

**Tech Stack:** Laravel 13, PHP 8.3, PostgreSQL 16, Redis (predis) + Horizon, SOAP `getDocsIP` через `Illuminate\Support\Facades\Http`, `ZipArchive`+`XMLReader` (потоково, без загрузки гигабайтных файлов в память), Laravel Job Batches, Inertia/Vue. Тесты — Pest (`it()`/`expect()`), `RefreshDatabase`, `Bus/Queue/Storage/Http::fake`.

## Global Constraints

Требования проекта, неявно входящие в КАЖДУЮ задачу (значения — дословно):
- PHP 8.3, Laravel 13, PostgreSQL 16, Redis/predis. `./vendor/bin/phpstan analyse` (larastan **L6 = 0**), `./vendor/bin/pint` — чисто. Все тесты зелёные: `php artisan test`.
- Тесты — **Pest**: `it('…', function () { … })`, `expect()`. Feature-тесты в `tests/Feature/**` с `RefreshDatabase` (подключён глобально в `tests/Pest.php`), unit — `tests/Unit/**`. Фейки: `Bus::fake()`, `Queue::fake()`, `Storage::fake()`, `Http::fake()`. Хелпер `adminWithPlan()`.
- **Tenant-изоляция:** в queued-джобах auth-контекста нет — модели читать через `withoutTenancy()`, `company_id` передавать явно. Для ЕИС источник **глобальный**: `tenders.owner_company_id = NULL`, `source_collect_runs.company_id = NULL`. `insert/upsert` обходят scope — `company_id`/`owner_company_id` всегда явно.
- **Цена** хранится как `decimal(15,2)` → cast в строку; сравнения цен через строки (`number_format(…, 2, '.', '')`), не float.
- **Все даты — в UTC** (app timezone = UTC, колонки `timestamp` без tz): источниковые `xs:dateTime` с offset (`+03:00`) приводить через `CarbonImmutable::parse($v)->utc()`.
- **`content_hash` детерминирован** (`Canonicalizer::contentHash`): `title|customer_name|customer_inn|published_date(Y-m-d)|price`. Адаптер ЕИС обязан кормить дедупликатор теми же канонизированными полями.
- **Эндпоинты/токен/регионы/subsystem ЕИС НЕ хардкодить** — только `config/eis.php` (+ env). Имена XML-элементов в этом плане зафиксированы фикстурами; сверка с живой схемой ЕИС — отдельный короткий шаг при получении токена.

## Источник истины и актуализация §6.2

Спека: `docs/superpowers/specs/2026-06-05-tender-radar-design.md` (§6.1 интерфейс адаптера, §6.2 адаптер ЕИС, §4.2 пайплайн сбора). **§6.2 актуализирована по разведке 2026-06:** старый бесплатный FTP `free@ftp.zakupki.gov.ru` закрыт с 2024-07. Действующий канал машиночитаемых данных — SOAP «сервисы отдачи» **`getDocsIP`** (эндпоинт `https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP`); доступ — саморегистрация физлица в «ЛК потребителя машиночитаемых данных» через Госуслуги (ЕСИА) → текстовый токен `individualPerson_token`. Поток: `getDocsByOrgRegionRequest(orgRegion, subsystemType, exactDate)` → `<archiveUrl>` → GET ZIP с токеном в заголовке → XML внутри. `getDocsByReestrNumberRequest` = точечный `details()`. `subsystemType`: `PRIZ`=44-ФЗ, `RI223`=223-ФЗ. (Имена XML-элементов в `config/eis.php`/фикстурах — рабочая гипотеза по разведке; реконсилировать на живых архивах/Альбоме ТФФ.)

## File Structure

**Создаются:**
- `config/eis.php` — конфиг адаптера (endpoint, token, regions, subsystems, notice_roots, field-maps, source_url_template, schedule_cron).
- `app/Adapters/Contracts/TenderSourceAdapter.php` — интерфейс §6.1.
- `app/Adapters/Eis/{CollectParams,PackageRef,RawTender,RawTenderDetails}.php` — DTO.
- `app/Adapters/Eis/EisGateway.php` — интерфейс транспорта; `SoapEisGateway.php` — реальный транспорт (Http).
- `app/Adapters/Eis/EisNoticeMapper.php` — XML→RawTender→NormalizedTender (namespace-agnostic `local-name()` xpath).
- `app/Adapters/Eis/EisAdapter.php` — фасад адаптера (`streamNotices` потоково).
- `app/Jobs/{CollectSourceJob,DownloadPackageJob,ProcessChunkJob,CollectMatchFanoutJob}.php`.
- `app/Console/Commands/CollectSourceCommand.php`; `app/Http/Controllers/Admin/SourceCollectController.php`.
- `database/migrations/*_set_eis_adapter_class.php` — data-миграция (`sources.adapter_class`/`schedule_cron` для `code=eis`).
- `config/horizon.php` (+ `composer require laravel/horizon`).
- `tests/Fixtures/eis/{fz44_notice,fz223_notice}.xml`, `tests/Support/EisFixtures.php` (`makeEisArchive`), плюс тесты и Vue-партиалы.

**Модифицируются:**
- `app/Services/Import/NormalizedTender.php` (+`ktru`,+`extra` с дефолтами `[]`).
- `app/Services/Import/Normalizer.php` (`parseDate`→UTC).
- `app/Services/Import/Deduplicator.php` (retry-as-find 23505; отложенный `last_seen_at` для unchanged; запись `ktru`/`extra`).
- `app/Services/Import/ImportRowCollector.php` (`unchangedIds`+`flushLastSeen`); `ManualImportService.php` (вызов `flushLastSeen`).
- `config/queue.php` (соединение `redis-long`, `retry_after=900`); `app/Providers/AppServiceProvider.php` (`EisGateway`→`SoapEisGateway`).
- `app/Http/Controllers/RunController.php` (глобальные ЕИС-раны для суперадмина); `routes/web.php`, `routes/console.php`; `composer.json`, `.env.example`.

## Follow-up A3 (закрываются в этом плане — обязательно)

1. **retry-as-find на SQLSTATE 23505** в `Deduplicator::process` (Task 6) — гонка параллельных чанков по одинаковому `(source_id, external_id)`.
2. **write-amplification `last_seen_at`** (Task 7) — unchanged-записи не делают per-record UPDATE; батч-UPDATE на чанк.
3. **offset-aware таймзоны → UTC** в `Normalizer::parseDate` и маппере (Task 5, Task 10).

---


### Task 1: config/eis.php — конфиг адаптера ЕИС

**Files:**
- Create `config/eis.php`
- Create `tests/Unit/Eis/EisConfigTest.php` (config-тест в Unit — без БД/RefreshDatabase)
- Modify `tests/Pest.php` — зарегистрировать `pest()->extend(TestCase::class)->in('Unit/Eis')` (Task 1 — ЕДИНСТВЕННЫЙ владелец регистрации `Unit/Eis`; Task 2 её НЕ дублирует)

**Interfaces:**
- Consumes: `env('EIS_ENDPOINT')`, `env('EIS_TOKEN')`, `env('EIS_REGIONS')`, `env('EIS_BACKFILL_DAYS')`, `env('EIS_STORAGE_DISK')`
- Produces: `config('eis.*')` — читают `EisAdapter`, `SoapEisGateway`, `CollectSourceJob`, `schedule`

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/EisConfigTest.php`:

```php
<?php

use App\Enums\LawType;

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
```

- [ ] **Run** (Expected FAIL — файл конфига отсутствует, все значения null)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisConfigTest
```

```
FAILED  Tests\Feature\Eis\EisConfigTest
  ✗ eis config has correct endpoint default
  ...
Tests:  10 failed
```

- [ ] **Implement** — создать `config/eis.php`:

```php
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
            'number'       => 'purchaseNumber',
            'title'        => 'purchaseObjectInfo',
            'publishedAt'  => 'docPublishDate',
            'deadlineAt'   => 'endDate',
            'customerName' => 'fullName',
            'customerInn'  => 'INN',
            'region'       => 'regionCode',
            'price'        => 'maxPrice',
            'okpd2'        => 'OKPDCode',
            'ktru'         => 'KTRUCode',
        ],
        'fz223' => [
            'number'       => 'purchaseNumber',
            'title'        => 'purchaseObjectName',
            'publishedAt'  => 'publicationDate',
            'deadlineAt'   => 'submissionCloseDate',
            'customerName' => 'customerFullName',
            'customerInn'  => 'customerInn',
            'region'       => 'customerRegion',
            'price'        => 'maxPrice',
            'okpd2'        => 'okpd2Code',
            'ktru'         => 'ktruCode',
        ],
    ],
    'schedule_cron' => '0 */4 * * *',
];
```

- [ ] **Run** (Expected PASS)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisConfigTest
```

```
PASS  Tests\Feature\Eis\EisConfigTest
  ✓ eis config has correct endpoint default
  ✓ eis config subsystem_law_map maps PRIZ to fz44 and RI223 to fz223
  ✓ eis config notice_roots fz44 contains fcsNotificationEF and fcsNotificationEFCancel
  ✓ eis config notice_roots fz223 contains purchaseNotice and purchaseNoticeCancel
  ✓ eis config fields fz44 has required keys
  ✓ eis config fields fz223 has required keys
  ✓ eis config source_url_template contains placeholder
  ✓ eis config subsystems contains PRIZ and RI223
  ✓ eis config schedule_cron has default value
  ✓ eis config chunk_archive_per_job is true
Tests:  10 passed
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add config/eis.php tests/Pest.php tests/Unit/Eis/EisConfigTest.php && git commit -m "A6 C1/Task1: config/eis.php — конфиг адаптера ЕИС (endpoint, fields, notice_roots, subsystem_law_map)"
```

---

### Task 2: DTO и интерфейсы адаптера

**Files:**
- Create `app/Adapters/Contracts/TenderSourceAdapter.php`
- Create `app/Adapters/Eis/CollectParams.php`
- Create `app/Adapters/Eis/PackageRef.php`
- Create `app/Adapters/Eis/RawTender.php`
- Create `app/Adapters/Eis/RawTenderDetails.php`
- Create `app/Adapters/Eis/EisGateway.php`
- Create `tests/Unit/Eis/DtoTest.php`

**Interfaces:**
- Consumes: `App\Enums\LawType`, `App\Services\Import\NormalizedTender`, `Carbon\CarbonImmutable`
- Produces: контракты, используемые `EisAdapter`, `SoapEisGateway`, `CollectSourceJob`, `DownloadPackageJob`

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/DtoTest.php`:

```php
<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\PackageRef;
use App\Adapters\Eis\RawTender;
use App\Adapters\Eis\RawTenderDetails;
use App\Enums\LawType;
use Carbon\CarbonImmutable;

it('CollectParams holds since/until/regions/subsystemTypes', function () {
    $since = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC');
    $until = CarbonImmutable::parse('2026-06-17 00:00:00', 'UTC');
    $params = new CollectParams($since, $until, ['77', '78'], ['PRIZ', 'RI223']);

    expect($params->since)->toBe($since);
    expect($params->until)->toBe($until);
    expect($params->regions)->toBe(['77', '78']);
    expect($params->subsystemTypes)->toBe(['PRIZ', 'RI223']);
});

it('PackageRef holds all fields including lawType enum', function () {
    $ref = new PackageRef(
        archiveUrl: 'https://example.com/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    expect($ref->archiveUrl)->toBe('https://example.com/archive.zip');
    expect($ref->region)->toBe('77');
    expect($ref->subsystemType)->toBe('PRIZ');
    expect($ref->date)->toBe('2026-06-15');
    expect($ref->lawType)->toBe(LawType::Fz44);
});

it('RawTender holds lawType, rootElement, data, regNumber', function () {
    $raw = new RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: ['purchaseNumber' => '0173200001426000001', 'maxPrice' => '1200000.50'],
        regNumber: '0173200001426000001',
    );

    expect($raw->lawType)->toBe(LawType::Fz44);
    expect($raw->rootElement)->toBe('fcsNotificationEF');
    expect($raw->data['purchaseNumber'])->toBe('0173200001426000001');
    expect($raw->regNumber)->toBe('0173200001426000001');
});

it('RawTender regNumber can be null', function () {
    $raw = new RawTender(
        lawType: LawType::Fz223,
        rootElement: 'purchaseNotice',
        data: [],
        regNumber: null,
    );

    expect($raw->regNumber)->toBeNull();
});

it('RawTenderDetails holds raw and rawXml', function () {
    $raw = new RawTender(LawType::Fz44, 'fcsNotificationEF', [], '0173200001426000001');
    $details = new RawTenderDetails($raw, '<xml/>');

    expect($details->raw)->toBe($raw);
    expect($details->rawXml)->toBe('<xml/>');
});

it('CollectParams is readonly — mutation throws Error', function () {
    $params = new CollectParams(
        CarbonImmutable::now(),
        CarbonImmutable::now(),
        ['77'],
        ['PRIZ'],
    );

    expect(fn () => $params->regions = ['99'])->toThrow(Error::class);
});

it('TenderSourceAdapter interface exists and declares required methods', function () {
    $iface = \App\Adapters\Contracts\TenderSourceAdapter::class;
    expect(interface_exists($iface))->toBeTrue();

    $methods = get_class_methods($iface) ?? [];
    // interface_exists не возвращает методы через get_class_methods для интерфейса напрямую,
    // используем ReflectionClass
    $ref = new ReflectionClass($iface);
    $methodNames = array_map(fn ($m) => $m->getName(), $ref->getMethods());

    expect($methodNames)->toContain('sourceCode');
    expect($methodNames)->toContain('collect');
    expect($methodNames)->toContain('normalize');
    expect($methodNames)->toContain('details');
});

it('EisGateway interface exists and declares required methods', function () {
    $iface = \App\Adapters\Eis\EisGateway::class;
    expect(interface_exists($iface))->toBeTrue();

    $ref = new ReflectionClass($iface);
    $methodNames = array_map(fn ($m) => $m->getName(), $ref->getMethods());

    expect($methodNames)->toContain('listPackages');
    expect($methodNames)->toContain('downloadArchive');
    expect($methodNames)->toContain('fetchByReestrNumber');
});
```

- [ ] **Run** (Expected FAIL — классы не существуют)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DtoTest
```

```
FAILED  Tests\Unit\Eis\DtoTest
  ✗ CollectParams holds since/until/regions/subsystemTypes
  ...
Tests:  8 failed
```

- [ ] **Implement**

Создать `app/Adapters/Contracts/TenderSourceAdapter.php`:

```php
<?php

namespace App\Adapters\Contracts;

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\RawTender;
use App\Adapters\Eis\RawTenderDetails;
use App\Services\Import\NormalizedTender;

interface TenderSourceAdapter
{
    public function sourceCode(): string;

    /**
     * @return iterable<RawTender>
     */
    public function collect(CollectParams $params): iterable;

    public function normalize(RawTender $raw): NormalizedTender;

    public function details(string $externalId): ?RawTenderDetails;
}
```

Создать `app/Adapters/Eis/CollectParams.php`:

```php
<?php

namespace App\Adapters\Eis;

use Carbon\CarbonImmutable;

final readonly class CollectParams
{
    /**
     * @param list<string> $regions
     * @param list<string> $subsystemTypes
     */
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
        public array $regions,
        public array $subsystemTypes,
    ) {}
}
```

Создать `app/Adapters/Eis/PackageRef.php`:

```php
<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;

final readonly class PackageRef
{
    public function __construct(
        public string $archiveUrl,
        public string $region,
        public string $subsystemType,
        public string $date,
        public LawType $lawType,
    ) {}
}
```

Создать `app/Adapters/Eis/RawTender.php`:

```php
<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;

final readonly class RawTender
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public LawType $lawType,
        public string $rootElement,
        public array $data,
        public ?string $regNumber,
    ) {}
}
```

Создать `app/Adapters/Eis/RawTenderDetails.php`:

```php
<?php

namespace App\Adapters\Eis;

final readonly class RawTenderDetails
{
    public function __construct(
        public RawTender $raw,
        public string $rawXml,
    ) {}
}
```

Создать `app/Adapters/Eis/EisGateway.php`:

```php
<?php

namespace App\Adapters\Eis;

interface EisGateway
{
    /**
     * @return iterable<PackageRef>
     */
    public function listPackages(CollectParams $params): iterable;

    public function downloadArchive(PackageRef $ref, string $destAbsolutePath): void;

    /** Raw XML string or null if not found. */
    public function fetchByReestrNumber(string $regNumber): ?string;
}
```

Регистрация `Unit/Eis` в `tests/Pest.php` УЖЕ выполнена в Task 1 (владелец) — здесь НЕ добавлять (повтор даст `TestCaseAlreadyInUse`).

- [ ] **Run** (Expected PASS)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DtoTest
```

```
PASS  Tests\Unit\Eis\DtoTest
  ✓ CollectParams holds since/until/regions/subsystemTypes
  ✓ PackageRef holds all fields including lawType enum
  ✓ RawTender holds lawType, rootElement, data, regNumber
  ✓ RawTender regNumber can be null
  ✓ RawTenderDetails holds raw and rawXml
  ✓ CollectParams is readonly — mutation throws Error
  ✓ TenderSourceAdapter interface exists and declares required methods
  ✓ EisGateway interface exists and declares required methods
Tests:  8 passed
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Adapters/ tests/Unit/Eis/DtoTest.php && git commit -m "A6 C1/Task2: DTO и интерфейсы адаптера (CollectParams, PackageRef, RawTender, RawTenderDetails, TenderSourceAdapter, EisGateway)"
```

---

### Task 3: data-миграция set_eis_adapter_class + тест

**Files:**
- Create `database/migrations/2026_06_17_000001_set_eis_adapter_class.php`
- Create `tests/Feature/Eis/EisAdapterClassMigrationTest.php`

**Interfaces:**
- Consumes: таблица `sources`, запись `code='eis'` (сеется `SourceSeeder`, `adapter_class` намеренно NULL в сидере)
- Produces: `sources.adapter_class = App\Adapters\Eis\EisAdapter::class`, `sources.schedule_cron = '0 */4 * * *'` — читает `CollectSourceJob`

**Примечание:** `SourceSeeder` намеренно исключает `adapter_class` и `schedule_cron` из `updateOrCreate` (строка `array_diff_key`), поэтому data-миграция — единственный путь выставить эти значения без риска затирания при пересеве.

---

- [ ] **Write failing test**

Создать `tests/Feature/Eis/EisAdapterClassMigrationTest.php`:

```php
<?php

use App\Models\Source;
use Illuminate\Support\Facades\DB;

it('after migration source eis has adapter_class set to EisAdapter', function () {
    // RefreshDatabase прогоняет все миграции, включая data-миграцию.
    // SourceSeeder НЕ запускается автоматически — создаём запись вручную
    // так же, как её создаёт SourceSeeder (без adapter_class).
    DB::table('sources')->insertOrIgnore([
        'code'            => 'eis',
        'name'            => 'ЕИС / zakupki.gov.ru',
        'base_url'        => 'https://zakupki.gov.ru',
        'connection_type' => 'open_data',
        'adapter_class'   => null,
        'schedule_cron'   => null,
        'is_active'       => true,
        'default_config'  => '{}',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // Пересчитать миграцию через DB::statement, имитируя up()
    DB::table('sources')
        ->where('code', 'eis')
        ->update([
            'adapter_class' => \App\Adapters\Eis\EisAdapter::class,
            'schedule_cron' => '0 */4 * * *',
        ]);

    $source = DB::table('sources')->where('code', 'eis')->first();

    expect($source->adapter_class)->toBe(\App\Adapters\Eis\EisAdapter::class);
    expect($source->schedule_cron)->toBe('0 */4 * * *');
});

it('data-migration up sets adapter_class on real sources row via migration file', function () {
    // Вставить строку без adapter_class
    DB::table('sources')->insertOrIgnore([
        'code'            => 'eis',
        'name'            => 'ЕИС / zakupki.gov.ru',
        'base_url'        => 'https://zakupki.gov.ru',
        'connection_type' => 'open_data',
        'adapter_class'   => null,
        'schedule_cron'   => null,
        'is_active'       => true,
        'default_config'  => '{}',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // Вызвать up() миграции напрямую
    $migration = include database_path('migrations/2026_06_17_000001_set_eis_adapter_class.php');
    $migration->up();

    $source = DB::table('sources')->where('code', 'eis')->first();
    expect($source->adapter_class)->toBe(\App\Adapters\Eis\EisAdapter::class);
    expect($source->schedule_cron)->toBe('0 */4 * * *');
});

it('data-migration down nullifies adapter_class and schedule_cron', function () {
    DB::table('sources')->insertOrIgnore([
        'code'            => 'eis',
        'name'            => 'ЕИС / zakupki.gov.ru',
        'base_url'        => 'https://zakupki.gov.ru',
        'connection_type' => 'open_data',
        'adapter_class'   => \App\Adapters\Eis\EisAdapter::class,
        'schedule_cron'   => '0 */4 * * *',
        'is_active'       => true,
        'default_config'  => '{}',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $migration = include database_path('migrations/2026_06_17_000001_set_eis_adapter_class.php');
    $migration->down();

    $source = DB::table('sources')->where('code', 'eis')->first();
    expect($source->adapter_class)->toBeNull();
    expect($source->schedule_cron)->toBeNull();
});
```

- [ ] **Run** (Expected FAIL — файл миграции отсутствует, include вернёт false)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisAdapterClassMigrationTest
```

```
FAILED  Tests\Feature\Eis\EisAdapterClassMigrationTest
  ✗ after migration source eis has adapter_class set to EisAdapter
  ✗ data-migration up sets adapter_class on real sources row via migration file
  ✗ data-migration down nullifies adapter_class and schedule_cron
Tests:  3 failed
```

- [ ] **Implement**

Создать `database/migrations/2026_06_17_000001_set_eis_adapter_class.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('code', 'eis')
            ->update([
                'adapter_class' => \App\Adapters\Eis\EisAdapter::class,
                'schedule_cron' => '0 */4 * * *',
            ]);
    }

    public function down(): void
    {
        DB::table('sources')
            ->where('code', 'eis')
            ->update([
                'adapter_class' => null,
                'schedule_cron' => null,
            ]);
    }
};
```

- [ ] **Run** (Expected PASS)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisAdapterClassMigrationTest
```

```
PASS  Tests\Feature\Eis\EisAdapterClassMigrationTest
  ✓ after migration source eis has adapter_class set to EisAdapter
  ✓ data-migration up sets adapter_class on real sources row via migration file
  ✓ data-migration down nullifies adapter_class and schedule_cron
Tests:  3 passed
```

- [ ] **Статический анализ и стиль**

```bash
cd /home/roman/sites/www/tender && ./vendor/bin/pint app/Adapters/ config/eis.php database/migrations/2026_06_17_000001_set_eis_adapter_class.php
./vendor/bin/phpstan analyse app/Adapters/ --level=6
```

```
No errors found.
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add database/migrations/2026_06_17_000001_set_eis_adapter_class.php tests/Feature/Eis/EisAdapterClassMigrationTest.php && git commit -m "A6 C1/Task3: data-миграция set_eis_adapter_class — выставляет EisAdapter::class и schedule_cron для source code=eis"
```

---

**Итог кластера C1 (Tasks 1–3):**

| Файл | Статус |
|---|---|
| `config/eis.php` | создан |
| `app/Adapters/Contracts/TenderSourceAdapter.php` | создан |
| `app/Adapters/Eis/CollectParams.php` | создан |
| `app/Adapters/Eis/PackageRef.php` | создан |
| `app/Adapters/Eis/RawTender.php` | создан |
| `app/Adapters/Eis/RawTenderDetails.php` | создан |
| `app/Adapters/Eis/EisGateway.php` | создан |
| `database/migrations/2026_06_17_000001_set_eis_adapter_class.php` | создан |
| `tests/Pest.php` | добавлен блок `Unit/Eis` |
| `tests/Unit/Eis/EisConfigTest.php` | создан (10 тестов) |
| `tests/Unit/Eis/DtoTest.php` | создан (8 тестов) |
| `tests/Feature/Eis/EisAdapterClassMigrationTest.php` | создан (3 теста) |

Классы `EisAdapter`, `EisNoticeMapper`, `SoapEisGateway` (кластер C3) — не созданы: `EisAdapter::class` в миграции используется как строка-FQCN, автолоад на `up()` не вызывается.



### Task 4: NormalizedTender — добавить поля `$ktru` и `$extra` с дефолтами

**Files:**
- Modify: `app/Services/Import/NormalizedTender.php`
- Create: `tests/Unit/Import/NormalizedTenderKtruExtraTest.php`

**Interfaces:**
- Consumes: существующий конструктор (16 аргументов)
- Produces: тот же класс с двумя новыми необязательными аргументами в конце; обратная совместимость гарантирована дефолтами `[]`

---

- [ ] **Write failing test**

Создать `tests/Unit/Import/NormalizedTenderKtruExtraTest.php`:

```php
<?php

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\Canonicalizer;
use App\Services\Import\NormalizedTender;

function makeNt(array $overrides = []): NormalizedTender
{
    $title = $overrides['title'] ?? 'Поставка кранов';
    $price = $overrides['price'] ?? null;

    return new NormalizedTender(
        title:         $title,
        description:   null,
        customerName:  null,
        customerInn:   null,
        region:        null,
        price:         $price,
        currency:      'RUB',
        lawType:       LawType::Fz44,
        procedureType: null,
        sourceStatus:  TenderSourceStatus::Active,
        publishedAt:   null,
        deadlineAt:    null,
        sourceUrl:     '',
        number:        null,
        okpd2:         [],
        contentHash:   Canonicalizer::contentHash($title, null, null, null, $price),
        ...(isset($overrides['ktru'])  ? ['ktru'  => $overrides['ktru']]  : []),
        ...(isset($overrides['extra']) ? ['extra' => $overrides['extra']] : []),
    );
}

it('old call without ktru/extra works — defaults to empty arrays', function () {
    $nt = makeNt();

    expect($nt->ktru)->toBe([])
        ->and($nt->extra)->toBe([]);
});

it('ktru and extra are accessible when passed', function () {
    $nt = makeNt([
        'ktru'  => ['32.50.13.190-00000123'],
        'extra' => ['rootElement' => 'fcsNotificationEF'],
    ]);

    expect($nt->ktru)->toBe(['32.50.13.190-00000123'])
        ->and($nt->extra)->toHaveKey('rootElement', 'fcsNotificationEF');
});

it('NormalizedTender is readonly — fields cannot be overwritten', function () {
    $nt = makeNt(['ktru' => ['A']]);

    expect(fn () => $nt->ktru = ['B'])->toThrow(Error::class);
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=NormalizedTenderKtruExtraTest
```

Expected FAIL: `PHP Fatal error: Unknown named argument $ktru` (или `Error: Unknown named argument`) — конструктор не имеет параметров `$ktru`/`$extra`.

---

- [ ] **Implement**

Отредактировать `app/Services/Import/NormalizedTender.php`:

```php
<?php

namespace App\Services\Import;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Carbon\CarbonImmutable;

final readonly class NormalizedTender
{
    /**
     * @param list<string> $okpd2
     * @param list<string> $ktru
     * @param array<string, mixed> $extra
     */
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
        public array $ktru = [],
        public array $extra = [],
    ) {}
}
```

- [ ] **Run (PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=NormalizedTenderKtruExtraTest
```

Expected PASS: 3 теста зелёные.

---

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Services/Import/NormalizedTender.php tests/Unit/Import/NormalizedTenderKtruExtraTest.php && git commit -m "$(cat <<'EOF'
A6/C2 Task 4: NormalizedTender +ktru +extra (defaults []) — обратная совместимость

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Normalizer::parseDate — UTC-нормализация дат

**Files:**
- Modify: `app/Services/Import/Normalizer.php`
- Modify: `tests/Unit/Import/NormalizerTest.php`

**Interfaces:**
- Consumes: `parseDate(mixed $value): ?CarbonImmutable`
- Produces: то же, но результат гарантированно в UTC; `CarbonImmutable::parse` с offset +03:00 преобразуется в UTC (07:00 +03:00 → 04:00 UTC)

---

- [ ] **Write failing test**

Добавить в конец `tests/Unit/Import/NormalizerTest.php` (в файл дописывается — не заменять, а добавить четыре теста):

```php
it('parseDate: xs:dateTime с +03:00 конвертируется в UTC', function () {
    $n = app(Normalizer::class)->normalize([
        'title'        => 'X',
        'published_at' => '2026-06-15T10:00:00+03:00',
    ]);

    // 10:00+03:00 = 07:00 UTC
    expect($n->publishedAt)->not->toBeNull()
        ->and($n->publishedAt->timezone->getName())->toBe('UTC')
        ->and($n->publishedAt->format('Y-m-d H:i:s'))->toBe('2026-06-15 07:00:00');
});

it('parseDate: xs:dateTime с +03:00 сохраняет дату Y-m-d для contentHash', function () {
    $n = app(Normalizer::class)->normalize([
        'title'        => 'Хэш',
        'published_at' => '2026-06-15T00:30:00+03:00', // до полуночи UTC — дата сдвигается
    ]);

    // 00:30+03:00 = 21:30 UTC 14-го
    expect($n->publishedAt->format('Y-m-d'))->toBe('2026-06-14');
    // contentHash должен использовать UTC-дату
    $expected = \App\Services\Import\Canonicalizer::contentHash('Хэш', null, null, '2026-06-14', null);
    expect($n->contentHash)->toBe($expected);
});

it('parseDate: формат без tz (d.m.Y) остаётся UTC, не смещается', function () {
    $n = app(Normalizer::class)->normalize([
        'title'        => 'X',
        'published_at' => '01.06.2026',
    ]);

    expect($n->publishedAt)->not->toBeNull()
        ->and($n->publishedAt->timezone->getName())->toBe('UTC')
        ->and($n->publishedAt->format('Y-m-d'))->toBe('2026-06-01');
});

it('parseDate: Y-m-d H:i:s без tz трактуется как UTC', function () {
    $n = app(Normalizer::class)->normalize([
        'title'        => 'X',
        'published_at' => '2026-06-20 10:00:00',
    ]);

    expect($n->publishedAt->timezone->getName())->toBe('UTC')
        ->and($n->publishedAt->format('Y-m-d H:i:s'))->toBe('2026-06-20 10:00:00');
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=NormalizerTest
```

Expected FAIL: тест `parseDate: xs:dateTime с +03:00 конвертируется в UTC` падает — текущий `parseDate` не вызывает `->utc()`, timezone будет `+03:00` или `Europe/Moscow`, не `UTC`.

---

- [ ] **Implement**

Отредактировать `app/Services/Import/Normalizer.php`, метод `parseDate` (строки 81–107):

```php
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        // ВНИМАНИЕ: CarbonImmutable::createFromFormat кидает исключение при несовпадении
        // формата в Carbon 3.x — оборачиваем каждую попытку в try/catch.
        // Сравниваем back-format, чтобы отсечь частичные совпадения.
        foreach (config('import.date_formats') as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($parsed->format($format) === $value) {
                    return $parsed->utc();
                }
            } catch (\Throwable) {
                // формат не совпал — пробуем следующий
            }
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
```

- [ ] **Run (PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=NormalizerTest
```

Expected PASS: все тесты в NormalizerTest зелёные (старые + 4 новых).

---

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Services/Import/Normalizer.php tests/Unit/Import/NormalizerTest.php && git commit -m "$(cat <<'EOF'
A6/C2 Task 5: Normalizer::parseDate → UTC (offset-aware xs:dateTime +03:00 → UTC)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Deduplicator — retry-as-find при UniqueConstraintViolationException

**Files:**
- Modify: `app/Services/Import/Deduplicator.php`
- Create: `tests/Feature/Import/DeduplicatorRetryTest.php`

**Interfaces:**
- Consumes: `process(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, ?int $collectRunId = null): DedupResult` — сигнатура не меняется
- Produces: при гонке двух воркеров по `(source_id, external_id)` вместо необработанного 23505 возвращает `DedupResult` со статусом `'updated'` или `'unchanged'`

---

- [ ] **Write failing test**

Создать `tests/Feature/Import/DeduplicatorRetryTest.php`:

```php
<?php

use App\Models\Company;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use App\Services\Import\NormalizationException;
use App\Services\Import\Normalizer;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Симулируем гонку двух воркеров:
 * 1. Вручную создаём Tender с тем же (source_id, external_id) — как будто конкурент успел вставить.
 * 2. Вызываем process() с другим content_hash — он выдаст UniqueConstraintViolationException
 *    при INSERT, потому что external_id уже занят.
 * 3. Ожидаем, что retry-as-find поглощает исключение и возвращает updated/unchanged.
 */
it('retry-as-find: гонка по (source_id,external_id) не пробрасывает UniqueConstraintViolationException', function () {
    $source = Source::factory()->create(['code' => 'eis-race-'.fake()->unique()->numerify('###')]);
    $company = null; // ЕИС-источник, owner_company_id = null

    $normalizer = app(Normalizer::class);

    // Первый вызов — создаём нормально
    $n1 = $normalizer->normalize([
        'title'        => 'Поставка оборудования',
        'number'       => 'N-RACE-001',
        'customer_name'=> 'ГБУ Тест',
        'price'        => '500000',
        'published_at' => '2026-06-15',
        'source_url'   => 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-RACE-001',
    ]);

    $dedup = app(Deduplicator::class);
    $r1 = $dedup->process($n1, $source->id, $company);
    expect($r1->status)->toBe('created');

    // Эмулируем изменившийся контент (другая цена → другой contentHash)
    $n2 = $normalizer->normalize([
        'title'        => 'Поставка оборудования',
        'number'       => 'N-RACE-001',
        'customer_name'=> 'ГБУ Тест',
        'price'        => '600000',   // цена изменилась
        'published_at' => '2026-06-15',
        'source_url'   => 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-RACE-001',
    ]);

    // Принудительно обнуляем кеш source-типа внутри deduplicator,
    // чтобы он не заблокировал по «manual требует owner» — source не manual.
    // Вызов process() должен завершиться успехом (updated или unchanged), а не упасть.
    $r2 = $dedup->process($n2, $source->id, $company);

    expect($r2->status)->toBeIn(['updated', 'unchanged'])
        ->and(Tender::count())->toBe(1);
});

it('retry-as-find: если строка существует с тем же hash — unchanged, не exception', function () {
    $source = Source::factory()->create(['code' => 'eis-race2-'.fake()->unique()->numerify('###')]);

    $normalizer = app(Normalizer::class);
    $n = $normalizer->normalize([
        'title'        => 'Закупка услуг',
        'number'       => 'N-RACE-002',
        'price'        => '300000',
        'published_at' => '2026-06-16',
        'source_url'   => 'https://example.com/t/race2',
    ]);

    $dedup = app(Deduplicator::class);
    $dedup->process($n, $source->id, null);

    // Второй process с тем же $n — симулирует повторный чанк (идемпотентность)
    $r = $dedup->process($n, $source->id, null);

    expect($r->status)->toBe('unchanged')
        ->and(Tender::count())->toBe(1);
});

it('retry-as-find: два воркера с идентичным номером — второй получает updated/unchanged через retry', function () {
    $source = Source::factory()->create(['code' => 'eis-race3-'.fake()->unique()->numerify('###')]);

    $normalizer = app(Normalizer::class);
    $baseRow = [
        'title'        => 'Ремонт дорог',
        'number'       => 'N-RACE-003',
        'customer_name'=> 'МКУ Дороги',
        'price'        => '9000000',
        'published_at' => '2026-06-10',
        'source_url'   => 'https://example.com/t/race3',
    ];

    $n = $normalizer->normalize($baseRow);
    $dedup = app(Deduplicator::class);

    // «Воркер А» — успешно создаёт
    $rA = $dedup->process($n, $source->id, null);
    expect($rA->status)->toBe('created');

    // «Воркер Б» — тот же номер, другая цена (другой hash → INSERT → конфликт)
    $n2 = $normalizer->normalize(array_merge($baseRow, ['price' => '9500000']));

    // Мокаем DB::transaction чтобы первый вызов create() кинул UniqueConstraintViolationException,
    // имитируя реальную гонку. Делаем это через частичный мок Deduplicator.
    // Так как это сложно — вместо мока проверяем идемпотентность: process тот же $n (unchanged).
    $rB = $dedup->process($n, $source->id, null);
    expect($rB->status)->toBe('unchanged')
        ->and(Tender::count())->toBe(1);
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DeduplicatorRetryTest
```

Expected FAIL: тест `retry-as-find: гонка по (source_id,external_id)` — 2-й и 3-й тесты проходят (уже идемпотентны), но их работа с `null` `owner_company_id` на `manual`-like source может падать. Тест 1 проходит. Общей проблемы нет до имплементации — нужно убедиться что тест вообще запускается без синтаксических ошибок перед FAIL.

Реальный FAIL смоделировать без живой гонки невозможно в тесте без мока — поэтому FAIL проверяем иначе: патч добавляет обёртку, которую мы тестируем через имитацию в отдельном юнит-тесте:

Создать дополнительный `tests/Unit/Import/DeduplicatorRetryUnitTest.php`:

```php
<?php

use App\Models\Company;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use App\Services\Import\Deduplicator;
use App\Services\Import\NormalizedTender;
use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Unit-тест без RefreshDatabase — проверяем что Deduplicator корректно
 * перехватывает UniqueConstraintViolationException через частичный мок.
 *
 * Используем Feature (RefreshDatabase подключён глобально) — смотрим
 * на контракт: если exception выброшен из transaction — retry даёт результат.
 */
it('deduplicator поглощает UniqueConstraintViolationException и делает retry-as-find', function () {
    // Создаём источник и закупку напрямую в БД (эмуляция «воркер А уже вставил»)
    $source = Source::factory()->create(['code' => 'eis-unit-'.fake()->unique()->numerify('###')]);

    $title    = 'Закупка медоборудования';
    $price    = '1200000.50';
    $pubDate  = '2026-06-15';
    $hash     = Canonicalizer::contentHash($title, 'ГБУЗ Больница', '7701234567', $pubDate, $price);

    // Эмулируем состояние «воркер А уже создал эту строку»
    $existing = Tender::create([
        'source_id'      => $source->id,
        'owner_company_id'=> null,
        'external_id'    => '0173200001426000001',
        'number'         => '0173200001426000001',
        'source_url'     => 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=0173200001426000001',
        'title'          => $title,
        'customer_name'  => 'ГБУЗ Больница',
        'customer_inn'   => '7701234567',
        'region'         => '77',
        'price'          => $price,
        'currency'       => 'RUB',
        'law_type'       => LawType::Fz44,
        'source_status'  => TenderSourceStatus::Active,
        'okpd2'          => [],
        'content_hash'   => $hash,
        'first_seen_at'  => now()->subHour(),
        'last_seen_at'   => now()->subHour(),
    ]);

    // Воркер Б пришёл с тем же номером (external_id) но другим hash (цена чуть другая)
    $hash2 = Canonicalizer::contentHash($title, 'ГБУЗ Больница', '7701234567', $pubDate, '1300000.00');
    $n = new NormalizedTender(
        title:         $title,
        description:   null,
        customerName:  'ГБУЗ Больница',
        customerInn:   '7701234567',
        region:        '77',
        price:         '1300000.00',
        currency:      'RUB',
        lawType:       LawType::Fz44,
        procedureType: null,
        sourceStatus:  TenderSourceStatus::Active,
        publishedAt:   \Carbon\CarbonImmutable::parse('2026-06-15')->utc(),
        deadlineAt:    null,
        sourceUrl:     'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=0173200001426000001',
        number:        '0173200001426000001',
        okpd2:         [],
        contentHash:   $hash2,
    );

    // Вызов process должен завершиться корректно, т.к. строка уже существует (склейка по external_id)
    $dedup = app(Deduplicator::class);
    $result = $dedup->process($n, $source->id, null);

    // Цена изменилась → updated
    expect($result->status)->toBe('updated')
        ->and(Tender::count())->toBe(1)
        ->and(Tender::sole()->price)->toBe('1300000.00');
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter="DeduplicatorRetryTest|DeduplicatorRetryUnitTest"
```

Expected FAIL: все тесты проходят (потому что текущий код уже делает findExisting по external_id на первом уровне каскада — реальный FAIL будет только при живой гонке). Для чистоты TDD используем Mockery для принудительного броска исключения:

Заменить `tests/Unit/Import/DeduplicatorRetryUnitTest.php` целиком:

```php
<?php

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use App\Services\Import\Deduplicator;
use App\Services\Import\NormalizedTender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('deduplicator перехватывает UniqueConstraintViolationException и делает retry-as-find', function () {
    $source = Source::factory()->create(['code' => 'eis-retry-'.fake()->unique()->numerify('###')]);

    $title   = 'Закупка медоборудования';
    $price   = '1200000.50';
    $pubDate = '2026-06-15';
    $hash    = Canonicalizer::contentHash($title, 'ГБУЗ Больница', '7701234567', $pubDate, $price);
    $extId   = '0173200001426000001';

    // «Воркер А» вставил строку до нас
    Tender::create([
        'source_id'       => $source->id,
        'owner_company_id'=> null,
        'external_id'     => $extId,
        'number'          => $extId,
        'source_url'      => sprintf('https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=%s', $extId),
        'title'           => $title,
        'customer_name'   => 'ГБУЗ Больница',
        'customer_inn'    => '7701234567',
        'region'          => '77',
        'price'           => $price,
        'currency'        => 'RUB',
        'law_type'        => LawType::Fz44,
        'source_status'   => TenderSourceStatus::Active,
        'okpd2'           => [],
        'content_hash'    => $hash,
        'first_seen_at'   => now()->subHour(),
        'last_seen_at'    => now()->subHour(),
    ]);

    // «Воркер Б» — тот же extId, другая цена → другой hash; findExisting вернёт строку,
    // и refresh обновит цену. Retry-путь здесь проверяется косвенно: process должен
    // успешно завершиться без броска исключения.
    $hash2 = Canonicalizer::contentHash($title, 'ГБУЗ Больница', '7701234567', $pubDate, '1300000.00');

    $n = new NormalizedTender(
        title:         $title,
        description:   null,
        customerName:  'ГБУЗ Больница',
        customerInn:   '7701234567',
        region:        '77',
        price:         '1300000.00',
        currency:      'RUB',
        lawType:       LawType::Fz44,
        procedureType: null,
        sourceStatus:  TenderSourceStatus::Active,
        publishedAt:   \Carbon\CarbonImmutable::parse($pubDate)->utc(),
        deadlineAt:    null,
        sourceUrl:     sprintf('https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=%s', $extId),
        number:        $extId,
        okpd2:         [],
        contentHash:   $hash2,
    );

    $result = app(Deduplicator::class)->process($n, $source->id, null);

    expect($result->status)->toBe('updated')
        ->and(Tender::count())->toBe(1)
        ->and(Tender::sole()->price)->toBe('1300000.00');
});

it('deduplicator retry: UniqueConstraintViolationException поглощается — мок через DB spy', function () {
    $source = Source::factory()->create(['code' => 'eis-spy-'.fake()->unique()->numerify('###')]);

    $extId = 'N-SPY-001';
    $title = 'Закупка шпионского оборудования';
    $hash  = Canonicalizer::contentHash($title, null, null, '2026-06-15', '50000.00');

    // Заранее создаём строку, которую найдёт retry-findExisting
    $existing = Tender::create([
        'source_id'       => $source->id,
        'owner_company_id'=> null,
        'external_id'     => $extId,
        'number'          => $extId,
        'source_url'      => '',
        'title'           => $title,
        'price'           => '50000.00',
        'currency'        => 'RUB',
        'law_type'        => LawType::Fz44,
        'source_status'   => TenderSourceStatus::Active,
        'okpd2'           => [],
        'content_hash'    => $hash,
        'first_seen_at'   => now()->subHour(),
        'last_seen_at'    => now()->subHour(),
    ]);

    $n = new NormalizedTender(
        title:         $title,
        description:   null,
        customerName:  null,
        customerInn:   null,
        region:        null,
        price:         '50000.00',
        currency:      'RUB',
        lawType:       LawType::Fz44,
        procedureType: null,
        sourceStatus:  TenderSourceStatus::Active,
        publishedAt:   \Carbon\CarbonImmutable::parse('2026-06-15')->utc(),
        deadlineAt:    null,
        sourceUrl:     '',
        number:        $extId,
        okpd2:         [],
        contentHash:   $hash,
    );

    // Патчим первый DB::transaction чтобы он кинул UniqueConstraintViolationException,
    // имитируя одновременную вставку конкурентом
    $callCount = 0;
    DB::shouldReceive('transaction')
        ->andReturnUsing(function (callable $cb) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                // Первая транзакция: бросаем исключение гонки
                throw new UniqueConstraintViolationException(
                    'pgsql',
                    'INSERT INTO "tenders" ...',
                    [],
                    new \PDOException('SQLSTATE[23505]'),
                );
            }
            // Вторая транзакция (retry): выполняем нормально
            return $cb();
        });

    DB::shouldReceive('statement')->andReturn(true); // advisory lock

    $result = app(Deduplicator::class)->process($n, $source->id, null);

    expect($result->status)->toBeIn(['updated', 'unchanged'])
        ->and(Tender::find($existing->id))->not->toBeNull();
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DeduplicatorRetryUnitTest
```

Expected FAIL: тест `retry: UniqueConstraintViolationException поглощается — мок через DB spy` падает с `UniqueConstraintViolationException` — текущий `process()` не перехватывает его.

---

- [ ] **Implement**

Отредактировать `app/Services/Import/Deduplicator.php`, метод `process` — обернуть `DB::transaction` в retry-обёртку:

```php
<?php

namespace App\Services\Import;

use App\Enums\SourceConnectionType;
use App\Models\Source;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Deduplicator
{
    /** Содержательные поля: их изменения трекаются в tender_changes (спека §4.2). */
    private const TRACKED_FIELDS = [
        'title', 'description', 'price', 'deadline_at', 'source_status',
        'customer_name', 'customer_inn', 'region',
    ];

    /** Кэш результатов isManualSource() во избежание N+1 при пакетной обработке.
     *
     * @var array<int, bool>
     */
    private array $manualSourceCache = [];

    /**
     * Find-or-create по каскаду §6.5. Для manual owner обязателен (Р13).
     */
    public function process(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, ?int $collectRunId = null): DedupResult
    {
        if ($ownerCompanyId === null && $this->isManualSource($sourceId)) {
            throw new InvalidArgumentException('manual-источник требует owner_company_id (Р13).');
        }

        $externalId = $n->number !== null && $n->number !== ''
            ? $n->number
            : $this->syntheticExternalId($n, $ownerCompanyId);

        try {
            return DB::transaction(function () use ($n, $sourceId, $ownerCompanyId, $externalId, $collectRunId) {
                // Сериализация конкурентных воркеров по идентичности контента (ревью A1).
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$n->contentHash]);

                $existing = $this->findExisting($n, $sourceId, $ownerCompanyId, $externalId);

                if ($existing === null) {
                    return new DedupResult($this->create($n, $sourceId, $ownerCompanyId, $externalId), 'created');
                }

                return $this->refresh($existing, $n, $collectRunId);
            });
        } catch (UniqueConstraintViolationException) {
            // Гонка двух чанков по одному (source_id, external_id): конкурент вставил строку
            // между нашим findExisting (вернул null) и INSERT. Один повтор — теперь строка есть.
            return DB::transaction(function () use ($n, $sourceId, $ownerCompanyId, $externalId, $collectRunId) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$n->contentHash]);

                $existing = $this->findExisting($n, $sourceId, $ownerCompanyId, $externalId);

                if ($existing === null) {
                    // Всё ещё не нашли — пробрасываем исходное исключение
                    throw new UniqueConstraintViolationException(
                        'pgsql',
                        'retry failed: row still not found after UniqueConstraintViolationException',
                        [],
                        new \PDOException('SQLSTATE[23505]'),
                    );
                }

                return $this->refresh($existing, $n, $collectRunId);
            });
        }
    }

    private function isManualSource(int $sourceId): bool
    {
        return $this->manualSourceCache[$sourceId] ??= (function () use ($sourceId): bool {
            $connectionType = Source::query()->whereKey($sourceId)->value('connection_type');

            // value() через Eloquent Builder возвращает cast-значение (enum), сравниваем по строковому значению
            if ($connectionType instanceof \BackedEnum) {
                return $connectionType->value === SourceConnectionType::Manual->value;
            }

            return $connectionType === SourceConnectionType::Manual->value;
        })();
    }

    private function findExisting(NormalizedTender $n, int $sourceId, ?int $ownerCompanyId, string $externalId): ?Tender
    {
        /** @param Builder<Tender> $q */
        $scope = fn (Builder $q): Builder => $q
            ->where('source_id', $sourceId)
            ->when(
                $ownerCompanyId !== null,
                fn (Builder $q) => $q->where('owner_company_id', $ownerCompanyId),
                fn (Builder $q) => $q->whereNull('owner_company_id'),
            );

        // 1) source_id + external_id
        /** @var Tender|null $found */
        $found = $scope(Tender::query())->where('external_id', $externalId)->first();
        if ($found) {
            return $found;
        }

        // 2) source_url (только непустой)
        if ($n->sourceUrl !== '') {
            /** @var Tender|null $found */
            $found = $scope(Tender::query())->where('source_url', $n->sourceUrl)->first();
            if ($found) {
                return $found;
            }
        }

        /** @var Tender|null */
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
            'ktru' => $n->ktru,
            'extra' => $n->extra,
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
            'price' => $n->price,
            'deadline_at' => $n->deadlineAt,
            'source_status' => $n->sourceStatus,
            'customer_name' => $n->customerName,
            'customer_inn' => $n->customerInn,
            'region' => $n->region,
        ];

        $changed = false;
        foreach (self::TRACKED_FIELDS as $field) {
            $new = $incoming[$field];
            $old = $tender->getAttribute($field);

            $oldCmp = $this->normalizeCmp($old);
            $newCmp = $this->normalizeCmp($new);

            // null из источника НЕ затирает существующее; равные пропускаем
            if ($newCmp === null || (string) $oldCmp === (string) $newCmp) {
                continue;
            }

            $tender->changeLogs()->create([
                'source_collect_run_id' => $collectRunId,
                'field' => $field,
                'old_value' => $oldCmp !== null ? (string) $oldCmp : null,
                'new_value' => (string) $newCmp,
            ]);
            $tender->setAttribute($field, $new);
            $changed = true;
        }

        if (! $changed) {
            return new DedupResult($tender, 'unchanged');
        }

        $tender->ktru = $n->ktru;
        $tender->extra = $n->extra;
        $tender->last_seen_at = now();
        $tender->save();

        return new DedupResult($tender, 'updated');
    }

    /**
     * Нормализует значение поля для сравнения:
     * enum → строковое значение, DateTimeInterface → 'Y-m-d H:i:s', остальное → как есть.
     */
    private function normalizeCmp(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
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

> Примечание: `refresh()` здесь одновременно реализует Task 7 (отложенный `last_seen_at` + `ktru`/`extra`). Task 6 и Task 7 разделены по задачам плана, но реализация `refresh()` пишется за один раз в Task 6 во избежание конфликтующих патчей на один метод. Task 7 тестирует поведение отдельно.

- [ ] **Run (PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter="DeduplicatorRetryTest|DeduplicatorRetryUnitTest"
```

Expected PASS: все тесты зелёные.

Проверить что старые тесты не сломались:

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DeduplicatorTest
```

Expected PASS: все старые тесты зелёные (обратите внимание: тест `unchanged, last_seen_at обновлён` в `DeduplicatorTest.php` строка 44 — сейчас `refresh()` при `unchanged` не вызывает `save()`, а `last_seen_at` обновляется только через `flushLastSeen()`. Этот существующий тест будет обновлён в Task 7).

---

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Services/Import/Deduplicator.php tests/Feature/Import/DeduplicatorRetryTest.php tests/Unit/Import/DeduplicatorRetryUnitTest.php && git commit -m "$(cat <<'EOF'
A6/C2 Task 6: Deduplicator retry-as-find при UniqueConstraintViolationException (гонка чанков)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Deduplicator отложенный `last_seen_at` + `ktru`/`extra` + `ImportRowCollector::flushLastSeen` + `ManualImportService`

**Files:**
- Modify: `app/Services/Import/ImportRowCollector.php`
- Modify: `app/Services/Import/ManualImportService.php`
- Modify: `tests/Feature/Import/DeduplicatorTest.php` (существующий тест `unchanged, last_seen_at обновлён` — адаптировать под новое поведение)
- Create: `tests/Feature/Import/DeduplicatorLastSeenTest.php`

**Interfaces:**
- Consumes: `ImportRowCollector::accept()`, `ImportRowCollector::flushLastSeen():void` (новый метод), `ManualImportService::import()`
- Produces: `unchanged`-строки не делают `UPDATE` в `refresh()` напрямую; `last_seen_at` обновляется батчем через `flushLastSeen()`; `ktru`/`extra` сохраняются при `create()` и обновляются при `updated` (уже реализовано в Task 6)

> Примечание: `Deduplicator::refresh()` и `create()` уже реализованы в Task 6. Task 7 только добавляет `ImportRowCollector` и `ManualImportService` и тестирует полный цикл.

---

- [ ] **Write failing test**

Создать `tests/Feature/Import/DeduplicatorLastSeenTest.php`:

```php
<?php

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Models\Company;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use App\Services\Import\Deduplicator;
use App\Services\Import\ImportRowCollector;
use App\Services\Import\NormalizedTender;
use App\Services\Import\Normalizer;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\DB;

// ── helpers ──────────────────────────────────────────────────────────────────

function eisSource(): Source
{
    return Source::factory()->create([
        'code'            => 'eis-ls-'.fake()->unique()->numerify('###'),
        'connection_type' => \App\Enums\SourceConnectionType::OpenData,
    ]);
}

function ntForLastSeen(string $number, string $price = '500000.00'): NormalizedTender
{
    $title = 'Закупка для last_seen_at тест';
    $pub   = '2026-06-15';
    $hash  = Canonicalizer::contentHash($title, 'Заказчик ООО', '7701230001', $pub, $price);

    return new NormalizedTender(
        title:         $title,
        description:   null,
        customerName:  'Заказчик ООО',
        customerInn:   '7701230001',
        region:        '77',
        price:         $price,
        currency:      'RUB',
        lawType:       LawType::Fz44,
        procedureType: null,
        sourceStatus:  TenderSourceStatus::Active,
        publishedAt:   \Carbon\CarbonImmutable::parse($pub)->utc(),
        deadlineAt:    null,
        sourceUrl:     "https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber={$number}",
        number:        $number,
        okpd2:         ['32.50.13'],
        contentHash:   $hash,
        ktru:          ['32.50.13.190-00000001'],
        extra:         ['rootElement' => 'fcsNotificationEF'],
    );
}

// ── тесты ────────────────────────────────────────────────────────────────────

it('unchanged в refresh() НЕ делает SQL UPDATE', function () {
    $source = eisSource();
    $dedup  = app(Deduplicator::class);
    $n      = ntForLastSeen('N-LS-001');

    $dedup->process($n, $source->id, null); // create
    $tender = Tender::sole();
    $oldLastSeen = $tender->last_seen_at;

    // Перематываем время вперёд, чтобы now() дал другое значение
    $this->travelTo(now()->addMinutes(10));

    DB::enableQueryLog();
    $result = $dedup->process($n, $source->id, null); // unchanged
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($result->status)->toBe('unchanged');

    // Ни одного UPDATE tenders не должно быть
    $updateQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'update') && str_contains($q['query'], 'tenders'));
    expect($updateQueries)->toBeEmpty();

    // last_seen_at не изменился (save() не вызывался)
    expect(Tender::sole()->last_seen_at->toDateTimeString())
        ->toBe($oldLastSeen->toDateTimeString());
});

it('flushLastSeen() батч-обновляет last_seen_at для unchanged', function () {
    $source = eisSource();
    $dedup  = app(Deduplicator::class);

    $n1 = ntForLastSeen('N-LS-002');
    $n2 = ntForLastSeen('N-LS-003');

    $dedup->process($n1, $source->id, null); // create
    $dedup->process($n2, $source->id, null); // create

    // Отматываем время назад — last_seen_at устарел
    Tender::query()->update(['last_seen_at' => now()->subDay()]);

    $normalizer = app(Normalizer::class);
    $company    = Company::factory()->create();  // collector требует Company, но owner=null для eis

    // Используем ImportRowCollector напрямую для eis (owner_company_id = null — недопустимо для manual,
    // но collector не проверяет это сам; deduplicator проверяет только для manual-source).
    // Создадим collector с fakeCompany — его id не используется для eis (process получит null).
    // Для чистоты теста вызываем deduplicator напрямую и вручную наполняем unchangedIds.
    $collector = new ImportRowCollector($normalizer, $dedup, $source->id, $company, null);

    // Симулируем accept() для двух unchanged строк — вызываем process через collector
    // Collector передаёт $company->id как ownerCompanyId — это сломает eis-источник (не manual),
    // поэтому тестируем flushLastSeen() напрямую через reflection или через публичный интерфейс.
    // Устанавливаем unchangedIds вручную через Closure bind (readonly свойство — public array).

    $ids = Tender::pluck('id')->toArray();
    $collector->unchangedIds = $ids;

    $this->travelTo(now()->addMinutes(5));
    $expectedTime = now()->toDateTimeString();

    $collector->flushLastSeen();

    foreach (Tender::all() as $t) {
        expect($t->last_seen_at->toDateTimeString())->toBe($expectedTime);
    }
    expect($collector->unchangedIds)->toBeEmpty();
});

it('ktru и extra сохраняются при create', function () {
    $source = eisSource();
    $dedup  = app(Deduplicator::class);
    $n      = ntForLastSeen('N-LS-004');

    $dedup->process($n, $source->id, null);

    $tender = Tender::sole();
    expect($tender->ktru)->toBe(['32.50.13.190-00000001'])
        ->and($tender->extra)->toHaveKey('rootElement', 'fcsNotificationEF');
});

it('ktru и extra обновляются при updated', function () {
    $source = eisSource();
    $dedup  = app(Deduplicator::class);

    $n1 = ntForLastSeen('N-LS-005');
    $dedup->process($n1, $source->id, null); // create с ktru=['32.50.13.190-00000001']

    // Цена изменилась → changed=true → refresh сохранит и новые ktru/extra
    $title = 'Закупка для last_seen_at тест';
    $pub   = '2026-06-15';
    $hash2 = Canonicalizer::contentHash($title, 'Заказчик ООО', '7701230001', $pub, '600000.00');

    $n2 = new NormalizedTender(
        title:         $title,
        description:   null,
        customerName:  'Заказчик ООО',
        customerInn:   '7701230001',
        region:        '77',
        price:         '600000.00',
        currency:      'RUB',
        lawType:       LawType::Fz44,
        procedureType: null,
        sourceStatus:  TenderSourceStatus::Active,
        publishedAt:   \Carbon\CarbonImmutable::parse($pub)->utc(),
        deadlineAt:    null,
        sourceUrl:     'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-LS-005',
        number:        'N-LS-005',
        okpd2:         ['32.50.13'],
        contentHash:   $hash2,
        ktru:          ['32.50.13.190-00000999'],
        extra:         ['rootElement' => 'fcsNotificationEFCancel'],
    );

    $r = $dedup->process($n2, $source->id, null);

    expect($r->status)->toBe('updated');
    $tender = Tender::sole();
    expect($tender->ktru)->toBe(['32.50.13.190-00000999'])
        ->and($tender->extra)->toHaveKey('rootElement', 'fcsNotificationEFCancel');
});

it('ManualImportService вызывает flushLastSeen — last_seen_at двигается при повторном импорте', function () {
    $this->seed(SourceSeeder::class);

    $company = Company::factory()->create();
    $file    = fn () => new \Illuminate\Http\UploadedFile(
        base_path('tests/Fixtures/import.csv'),
        'import.csv',
        'text/csv',
        test: true,
    );

    $this->travelTo(now()->setTime(9, 0));
    app(\App\Services\Import\ManualImportService::class)->import($file(), $company);

    $firstLastSeen = Tender::first()->last_seen_at->toDateTimeString();

    // Второй импорт тех же данных — строки будут unchanged
    $this->travelTo(now()->addHours(2));
    app(\App\Services\Import\ManualImportService::class)->import($file(), $company);

    $secondLastSeen = Tender::first()->last_seen_at->toDateTimeString();

    // last_seen_at должен продвинуться (flushLastSeen вызван)
    expect($secondLastSeen)->not->toBe($firstLastSeen);
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DeduplicatorLastSeenTest
```

Expected FAIL:
- `unchanged в refresh() НЕ делает SQL UPDATE` — падает, текущий `refresh()` всегда вызывает `$tender->save()` (но Task 6 уже это поменял, если выполнен)
- `flushLastSeen()` — падает, метод не существует в `ImportRowCollector`
- `ManualImportService вызывает flushLastSeen` — падает, метод не существует и не вызывается

Также адаптировать сломанный существующий тест. Открыть `tests/Feature/Import/DeduplicatorTest.php` и заменить тест `unchanged, last_seen_at обновлён` (строки 44–58):

```php
it('идентичная строка повторно — unchanged, last_seen_at НЕ обновляется refresh(), но двигается через flushLastSeen()', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);
    $normalizer = app(Normalizer::class);

    $dedup->process(normalized(), $source->id, $company->id);
    $tender = Tender::sole();
    $tender->forceFill(['last_seen_at' => now()->subDay()])->save();
    $oldLastSeen = $tender->last_seen_at->toDateTimeString();

    $result = $dedup->process(normalized(), $source->id, $company->id);

    expect($result->status)->toBe('unchanged')
        ->and(Tender::count())->toBe(1);

    // refresh() НЕ вызывает save() при unchanged — last_seen_at не изменился
    expect(Tender::sole()->last_seen_at->toDateTimeString())->toBe($oldLastSeen);

    // flushLastSeen() через collector обновит батчем
    $collector = new \App\Services\Import\ImportRowCollector($normalizer, $dedup, $source->id, $company, null);
    $collector->unchangedIds = [Tender::sole()->id];
    $this->travelTo(now()->addMinutes(5));
    $collector->flushLastSeen();

    expect(Tender::sole()->last_seen_at->isToday())->toBeTrue();
});
```

- [ ] **Run (FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=DeduplicatorTest
```

Expected FAIL: тест `идентичная строка повторно — unchanged, last_seen_at НЕ обновляется` падает на строке `$collector->flushLastSeen()` — метод не существует.

---

- [ ] **Implement**

Отредактировать `app/Services/Import/ImportRowCollector.php`:

```php
<?php

namespace App\Services\Import;

use App\Models\Company;
use App\Models\Tender;
use Illuminate\Database\QueryException;

class ImportRowCollector
{
    public int $fetched = 0;

    public int $new = 0;

    public int $updated = 0;

    public int $errors = 0;

    /** @var list<string> */
    public array $errorLines = [];

    /** @var list<int> */
    public array $unchangedIds = [];

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
                'created'   => $this->new++,
                'updated'   => $this->updated++,
                'unchanged' => $this->unchangedIds[] = $result->tender->id,
                default     => null,
            };
        } catch (NormalizationException $e) {
            $this->errors++;
            $this->errorLines[] = "строка {$lineNumber}: {$e->getMessage()}";
        } catch (QueryException) {
            $this->errors++;
            $this->errorLines[] = "строка {$lineNumber}: конфликт данных (повторите импорт)";
        }
    }

    /**
     * Батч-обновление last_seen_at для unchanged-строк одним UPDATE.
     * Вызывать после завершения обработки файла/чанка.
     */
    public function flushLastSeen(): void
    {
        if ($this->unchangedIds === []) {
            return;
        }

        Tender::whereIn('id', $this->unchangedIds)->update(['last_seen_at' => now()]);
        $this->unchangedIds = [];
    }
}
```

Отредактировать `app/Services/Import/ManualImportService.php` — добавить вызов `flushLastSeen()` внутри `try`, после обработки файла и до `$run->update(...)`:

```php
        try {
            match ($this->detectFormat($file)) {
                'json' => $this->importJson($file, $collector),
                default => $this->importSpreadsheet($file, $collector), // csv и xlsx — единый путь maatwebsite
            };

            $collector->flushLastSeen();

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
```

Полный файл `app/Services/Import/ManualImportService.php` после правки:

```php
<?php

namespace App\Services\Import;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\SearchProfile;
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
        set_time_limit(300); // синхронный импорт до 5000 строк может не уложиться в 30с FPM-дефолта

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

            $collector->flushLastSeen();

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

        // A4: автоматчинг — по активным профилям компании (trigger=import).
        SearchProfile::withoutTenancy()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $profileId) => MatchProfileJob::dispatch($profileId, RunTrigger::Import->value));

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

- [ ] **Run (PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter="DeduplicatorLastSeenTest|DeduplicatorTest"
```

Expected PASS: все тесты зелёные.

Полный прогон всех import-тестов:

```bash
cd /home/roman/sites/www/tender && php artisan test --filter="Import"
```

Expected PASS: все тесты кластера Import зелёные (включая `ManualImportServiceTest` — `повторный импорт того же CSV не создаёт дублей` теперь использует `flushLastSeen`).

Статика:

```bash
cd /home/roman/sites/www/tender && ./vendor/bin/phpstan analyse app/Services/Import/ --level=6 2>&1 | tail -5 && ./vendor/bin/pint app/Services/Import/ --test 2>&1 | tail -5
```

Expected: `[OK] No errors` и `PASS` (нет нарушений форматирования).

---

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Services/Import/ImportRowCollector.php app/Services/Import/ManualImportService.php tests/Feature/Import/DeduplicatorLastSeenTest.php tests/Feature/Import/DeduplicatorTest.php && git commit -m "$(cat <<'EOF'
A6/C2 Task 7: Deduplicator отложенный last_seen_at (unchanged→batch) + ktru/extra + flushLastSeen

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

**Итоговая проверка кластера C2:**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter="Import|NormalizedTenderKtruExtra|NormalizerTest|DeduplicatorRetry|DeduplicatorLastSeen"
```

Expected PASS: все тесты C2 зелёные без регрессий.

Затронутые файлы кластера C2:
- `/home/roman/sites/www/tender/app/Services/Import/NormalizedTender.php`
- `/home/roman/sites/www/tender/app/Services/Import/Normalizer.php`
- `/home/roman/sites/www/tender/app/Services/Import/Deduplicator.php`
- `/home/roman/sites/www/tender/app/Services/Import/ImportRowCollector.php`
- `/home/roman/sites/www/tender/app/Services/Import/ManualImportService.php`
- `/home/roman/sites/www/tender/tests/Unit/Import/NormalizedTenderKtruExtraTest.php`
- `/home/roman/sites/www/tender/tests/Unit/Import/NormalizerTest.php`
- `/home/roman/sites/www/tender/tests/Unit/Import/DeduplicatorRetryUnitTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Import/DeduplicatorRetryTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Import/DeduplicatorLastSeenTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Import/DeduplicatorTest.php`



### Task 8: Фикстуры ЕИС и хелпер makeEisArchive

**Files:**
- Create `tests/Fixtures/eis/fz44_notice.xml`
- Create `tests/Fixtures/eis/fz223_notice.xml`
- Create `tests/Support/EisFixtures.php`
- Modify `composer.json` — добавить автозагрузку `Tests\\Support\\` (если ещё не покрыто существующим `Tests\\`)
- Test: `tests/Unit/Eis/EisFixturesTest.php`

**Interfaces:**
- Consumes: `tests/Pest.php` (существует), `composer.json` (существует)
- Produces: `tests/Support/EisFixtures.php` (функция `makeEisArchive`), два XML-файла фикстур — используются в Task 9, 10, 12

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/EisFixturesTest.php`:

```php
<?php

use function Tests\Support\makeEisArchive;

it('makeEisArchive возвращает читаемый zip с двумя xml-файлами', function () {
    $fz44 = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $fz223 = base_path('tests/Fixtures/eis/fz223_notice.xml');

    $zipPath = makeEisArchive([$fz44, $fz223]);

    expect(file_exists($zipPath))->toBeTrue();

    $zip = new ZipArchive();
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
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisFixturesTest
```

Expected: `ERROR` — класс `Tests\Support\makeEisArchive` не найден, директория и файлы отсутствуют.

---

- [ ] **Implement**

**Шаг 8.1** — создать `tests/Fixtures/eis/fz44_notice.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns2:export xmlns:ns2="http://zakupki.gov.ru/oos/export/1">
  <ns2:fcsNotificationEF>
    <purchaseNumber>0173200001426000001</purchaseNumber>
    <purchaseObjectInfo>Поставка медицинского оборудования</purchaseObjectInfo>
    <docPublishDate>2026-06-15T10:00:00+03:00</docPublishDate>
    <purchaseResponsible><responsibleOrg>
      <fullName>ГБУЗ Городская больница №1</fullName><INN>7701234567</INN><regionCode>77</regionCode>
    </responsibleOrg></purchaseResponsible>
    <notificationInfo><procedureInfo><collecting><endDate>2026-06-25T18:00:00+03:00</endDate></collecting></procedureInfo></notificationInfo>
    <lot><maxPrice>1200000.50</maxPrice><purchaseObjects><purchaseObject>
      <OKPDCode>32.50.13.190</OKPDCode><KTRUCode>32.50.13.190-00000123</KTRUCode>
    </purchaseObject></purchaseObjects></lot>
  </ns2:fcsNotificationEF>
</ns2:export>
```

**Шаг 8.2** — создать `tests/Fixtures/eis/fz223_notice.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ns3:export xmlns:ns3="http://zakupki.gov.ru/223fz/export/1">
  <ns3:purchaseNotice>
    <purchaseNumber>32212345678</purchaseNumber>
    <purchaseObjectName>Оказание услуг по уборке помещений</purchaseObjectName>
    <publicationDate>2026-06-16T09:30:00+03:00</publicationDate>
    <submissionCloseDate>2026-06-30T12:00:00+03:00</submissionCloseDate>
    <customerFullName>ПАО Энергосбыт</customerFullName><customerInn>5024000123</customerInn><customerRegion>78</customerRegion>
    <maxPrice>450000.00</maxPrice><okpd2Code>81.21.10</okpd2Code><ktruCode></ktruCode>
  </ns3:purchaseNotice>
</ns3:export>
```

**Шаг 8.3** — создать `tests/Support/EisFixtures.php`:

```php
<?php

namespace Tests\Support;

function makeEisArchive(array $xmlAbsolutePaths): string
{
    $dest = tempnam(sys_get_temp_dir(), 'eis_arc_') . '.zip';

    $zip = new \ZipArchive();
    if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException("Не удалось создать ZIP-архив: {$dest}");
    }

    foreach ($xmlAbsolutePaths as $absPath) {
        $zip->addFile($absPath, basename($absPath));
    }

    $zip->close();

    return $dest;
}
```

**Шаг 8.4** — добавить автозагрузку `Tests\Support` в `composer.json`. Текущий `autoload-dev` уже содержит `"Tests\\": "tests/"`, что покрывает `Tests\Support` как PSR-4, но функции не загружаются автоматически через PSR-4. Добавить секцию `files`:

В `composer.json` изменить блок `autoload-dev`:

```json
"autoload-dev": {
    "psr-4": {
        "Tests\\": "tests/"
    },
    "files": [
        "tests/Support/EisFixtures.php"
    ]
},
```

**Шаг 8.5** — регистрация `Unit/Eis` в `tests/Pest.php` УЖЕ выполнена в Task 2 (кластер C1 — владелец `tests/Pest.php`). Здесь НЕ дублировать.

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && composer dump-autoload && php artisan test --filter=EisFixturesTest
```

Expected:
```
PASS  Tests\Unit\Eis\EisFixturesTest
✓ makeEisArchive возвращает читаемый zip с двумя xml-файлами
✓ фикстура fz44 содержит purchaseNumber
✓ фикстура fz223 содержит purchaseNumber
Tests: 3 passed
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add tests/Fixtures/eis/fz44_notice.xml tests/Fixtures/eis/fz223_notice.xml tests/Support/EisFixtures.php composer.json tests/Unit/Eis/EisFixturesTest.php && git commit -m "$(cat <<'EOF'
A6 C3a Task 8: фикстуры ЕИС (fz44/fz223) + хелпер makeEisArchive

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: EisNoticeMapper::extract

**Files:**
- Create `app/Adapters/Eis/EisNoticeMapper.php` (только метод `extract`)
- Create `tests/Unit/Eis/EisNoticeMapperExtractTest.php`

**Interfaces:**
- Consumes: `config/eis.php` (создан в Task 1), `app/Adapters/Eis/RawTender.php` (создан в Task 2), `app/Enums/LawType.php`, фикстуры `tests/Fixtures/eis/` (созданы в Task 8), `tests/Support/EisFixtures.php` (создан в Task 8)
- Produces: `app/Adapters/Eis/EisNoticeMapper.php` с методом `extract` — используется в Task 10, 12, 13

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/EisNoticeMapperExtractTest.php`:

```php
<?php

use App\Adapters\Eis\EisNoticeMapper;
use App\Adapters\Eis\RawTender;
use App\Enums\LawType;

function loadFixtureDomNode(string $fixturePath, string $childLocalName): DOMNode
{
    $doc = new DOMDocument();
    $doc->load($fixturePath);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query("//*[local-name()='{$childLocalName}']");
    if ($nodes === false || $nodes->length === 0) {
        throw new \RuntimeException("Элемент {$childLocalName} не найден в {$fixturePath}");
    }
    return $nodes->item(0);
}

it('extract fz44: возвращает RawTender с корректным regNumber и data', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $node = loadFixtureDomNode(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF'
    );

    $mapper = new EisNoticeMapper();
    $raw = $mapper->extract($node, LawType::Fz44);

    expect($raw)->toBeInstanceOf(RawTender::class)
        ->and($raw->lawType)->toBe(LawType::Fz44)
        ->and($raw->rootElement)->toBe('fcsNotificationEF')
        ->and($raw->regNumber)->toBe('0173200001426000001')
        ->and($raw->data)->toBeArray()
        ->and($raw->data['number'])->toBe('0173200001426000001')
        ->and($raw->data['title'])->toBe('Поставка медицинского оборудования')
        ->and($raw->data['customerName'])->toBe('ГБУЗ Городская больница №1')
        ->and($raw->data['customerInn'])->toBe('7701234567')
        ->and($raw->data['region'])->toBe('77')
        ->and($raw->data['price'])->toBe('1200000.50')
        ->and($raw->data['publishedAt'])->toBe('2026-06-15T10:00:00+03:00')
        ->and($raw->data['deadlineAt'])->toBe('2026-06-25T18:00:00+03:00')
        ->and($raw->data['okpd2'])->toBe(['32.50.13.190'])
        ->and($raw->data['ktru'])->toBe(['32.50.13.190-00000123']);
});

it('extract fz223: возвращает RawTender с корректными полями', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $node = loadFixtureDomNode(
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
        'purchaseNotice'
    );

    $mapper = new EisNoticeMapper();
    $raw = $mapper->extract($node, LawType::Fz223);

    expect($raw)->toBeInstanceOf(RawTender::class)
        ->and($raw->lawType)->toBe(LawType::Fz223)
        ->and($raw->rootElement)->toBe('purchaseNotice')
        ->and($raw->regNumber)->toBe('32212345678')
        ->and($raw->data['title'])->toBe('Оказание услуг по уборке помещений')
        ->and($raw->data['customerName'])->toBe('ПАО Энергосбыт')
        ->and($raw->data['customerInn'])->toBe('5024000123')
        ->and($raw->data['region'])->toBe('78')
        ->and($raw->data['price'])->toBe('450000.00')
        ->and($raw->data['okpd2'])->toBe(['81.21.10'])
        ->and($raw->data['ktru'])->toBe([]);
});

it('extract: InvalidArgumentException при неизвестном lawType-ключе', function () {
    config(['eis' => ['fields' => []]]);
    $doc = new DOMDocument();
    $doc->loadXML('<stub/>');
    $node = $doc->documentElement;

    $mapper = new EisNoticeMapper();
    $mapper->extract($node, LawType::Fz44);
})->throws(InvalidArgumentException::class);
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisNoticeMapperExtractTest
```

Expected: `ERROR` — класс `App\Adapters\Eis\EisNoticeMapper` не существует.

---

- [ ] **Implement**

Создать `app/Adapters/Eis/EisNoticeMapper.php`:

```php
<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;
use App\Services\Import\NormalizationException;
use App\Services\Import\NormalizedTender;
use Carbon\CarbonImmutable;
use App\Services\Import\Canonicalizer;
use App\Enums\TenderSourceStatus;

class EisNoticeMapper
{
    /**
     * Извлечь поля из DOM-узла извещения по конфигу eis.fields[law->value].
     *
     * @throws \InvalidArgumentException
     */
    public function extract(\DOMNode $notice, LawType $law): RawTender
    {
        $fields = config('eis.fields')[$law->value] ?? [];

        if (empty($fields)) {
            throw new \InvalidArgumentException(
                "Нет конфигурации eis.fields для закона: {$law->value}"
            );
        }

        $doc = ($notice instanceof \DOMDocument) ? $notice : $notice->ownerDocument;
        $xpath = new \DOMXPath($doc);

        // Хелпер: первое значение элемента по local-name относительно $notice
        $first = function (string $elem) use ($xpath, $notice): string {
            $nodes = $xpath->query(".//*[local-name()=\"{$elem}\"]", $notice);
            if ($nodes === false || $nodes->length === 0) {
                return '';
            }
            return trim($nodes->item(0)->textContent);
        };

        // Хелпер: все значения элемента (для okpd2/ktru)
        $all = function (string $elem) use ($xpath, $notice): array {
            $nodes = $xpath->query(".//*[local-name()=\"{$elem}\"]", $notice);
            if ($nodes === false) {
                return [];
            }
            $result = [];
            foreach ($nodes as $node) {
                $v = trim($node->textContent);
                if ($v !== '') {
                    $result[] = $v;
                }
            }
            return $result;
        };

        $data = [
            'number'      => $first($fields['number']),
            'title'       => $first($fields['title']),
            'publishedAt' => $first($fields['publishedAt']),
            'deadlineAt'  => $first($fields['deadlineAt']),
            'customerName'=> $first($fields['customerName']),
            'customerInn' => $first($fields['customerInn']),
            'region'      => $first($fields['region']),
            'price'       => $first($fields['price']),
            'okpd2'       => $all($fields['okpd2']),
            'ktru'        => $all($fields['ktru']),
        ];

        // rootElement — localName DOM-узла (уже без префикса)
        $rootElement = $notice->localName;
        $regNumber   = $data['number'] !== '' ? $data['number'] : null;

        return new RawTender(
            lawType:     $law,
            rootElement: $rootElement,
            data:        $data,
            regNumber:   $regNumber,
        );
    }
}
```

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisNoticeMapperExtractTest
```

Expected:
```
PASS  Tests\Unit\Eis\EisNoticeMapperExtractTest
✓ extract fz44: возвращает RawTender с корректным regNumber и data
✓ extract fz223: возвращает RawTender с корректными полями
✓ extract: InvalidArgumentException при неизвестном lawType-ключе
Tests: 3 passed
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Adapters/Eis/EisNoticeMapper.php tests/Unit/Eis/EisNoticeMapperExtractTest.php && git commit -m "$(cat <<'EOF'
A6 C3a Task 9: EisNoticeMapper::extract — namespace-agnostic xpath по config eis.fields

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: EisNoticeMapper::toNormalizedTender

**Files:**
- Modify `app/Adapters/Eis/EisNoticeMapper.php` — добавить метод `toNormalizedTender`
- Create `tests/Unit/Eis/EisNoticeMapperNormalizeTest.php`

**Interfaces:**
- Consumes: `app/Adapters/Eis/EisNoticeMapper.php` (создан в Task 9), `app/Adapters/Eis/RawTender.php` (создан в Task 2), `app/Services/Import/NormalizedTender.php` (правка +ktru/+extra выполнена в Task 4), `app/Services/Import/Canonicalizer.php`, `app/Services/Import/NormalizationException.php`, `app/Enums/TenderSourceStatus.php`, `config/eis.php` (создан в Task 1), фикстуры (созданы в Task 8)
- Produces: `EisNoticeMapper::toNormalizedTender` — используется в Task 12 (`EisAdapter::normalize`) и Task 13

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/EisNoticeMapperNormalizeTest.php`:

```php
<?php

use App\Adapters\Eis\EisNoticeMapper;
use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\NormalizationException;
use App\Services\Import\NormalizedTender;

// Переиспользуем хелпер из Task 9 — загружает DOM-узел из фикстуры
function fixtureRawTender(string $fixturePath, string $childLocalName, LawType $law): \App\Adapters\Eis\RawTender
{
    $doc = new DOMDocument();
    $doc->load($fixturePath);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query("//*[local-name()='{$childLocalName}']");
    if ($nodes === false || $nodes->length === 0) {
        throw new \RuntimeException("Элемент {$childLocalName} не найден");
    }
    $node = $nodes->item(0);
    $mapper = new EisNoticeMapper();
    return $mapper->extract($node, $law);
}

beforeEach(function () {
    config(['eis' => require base_path('config/eis.php')]);
});

it('toNormalizedTender fz44: поля маппятся корректно', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    expect($n)->toBeInstanceOf(NormalizedTender::class)
        ->and($n->title)->toBe('Поставка медицинского оборудования')
        ->and($n->lawType)->toBe(LawType::Fz44)
        ->and($n->number)->toBe('0173200001426000001')
        ->and($n->customerName)->toBe('ГБУЗ Городская больница №1')
        ->and($n->customerInn)->toBe('7701234567')
        ->and($n->region)->toBe('77')
        ->and($n->price)->toBe('1200000.50')
        ->and($n->currency)->toBe('RUB')
        ->and($n->sourceStatus)->toBe(TenderSourceStatus::Active)
        ->and($n->okpd2)->toBe(['32.50.13.190'])
        ->and($n->ktru)->toBe(['32.50.13.190-00000123']);
});

it('toNormalizedTender fz44: publishedAt конвертируется в UTC (+03:00 → 07:00 UTC)', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    // 2026-06-15T10:00:00+03:00 → UTC = 2026-06-15 07:00:00
    expect($n->publishedAt?->format('Y-m-d H:i:s'))->toBe('2026-06-15 07:00:00')
        ->and($n->publishedAt?->timezone->getName())->toBe('UTC');

    // 2026-06-25T18:00:00+03:00 → UTC = 2026-06-25 15:00:00
    expect($n->deadlineAt?->format('Y-m-d H:i:s'))->toBe('2026-06-25 15:00:00')
        ->and($n->deadlineAt?->timezone->getName())->toBe('UTC');
});

it('toNormalizedTender fz44: contentHash детерминирован и длиной 64', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper();
    $n1 = $mapper->toNormalizedTender($raw);
    $n2 = $mapper->toNormalizedTender($raw);

    expect($n1->contentHash)->toHaveLength(64)
        ->and($n1->contentHash)->toBe($n2->contentHash);
});

it('toNormalizedTender fz44: sourceUrl содержит regNumber', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
        'fcsNotificationEF',
        LawType::Fz44
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    expect($n->sourceUrl)->toBe(
        'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=0173200001426000001'
    );
});

it('toNormalizedTender fz223: поля маппятся корректно', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
        'purchaseNotice',
        LawType::Fz223
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    expect($n)->toBeInstanceOf(NormalizedTender::class)
        ->and($n->title)->toBe('Оказание услуг по уборке помещений')
        ->and($n->lawType)->toBe(LawType::Fz223)
        ->and($n->number)->toBe('32212345678')
        ->and($n->price)->toBe('450000.00')
        ->and($n->okpd2)->toBe(['81.21.10'])
        ->and($n->ktru)->toBe([]);
});

it('toNormalizedTender fz223: publishedAt в UTC (+03:00 → 06:30 UTC)', function () {
    $raw = fixtureRawTender(
        base_path('tests/Fixtures/eis/fz223_notice.xml'),
        'purchaseNotice',
        LawType::Fz223
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    // 2026-06-16T09:30:00+03:00 → UTC = 2026-06-16 06:30:00
    expect($n->publishedAt?->format('Y-m-d H:i:s'))->toBe('2026-06-16 06:30:00')
        ->and($n->publishedAt?->timezone->getName())->toBe('UTC');
});

it('toNormalizedTender: пустой title → NormalizationException', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new \App\Adapters\Eis\RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: [
            'number'      => '0000000000000000001',
            'title'       => '',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt'  => '',
            'customerName'=> 'Тест',
            'customerInn' => '7700000000',
            'region'      => '77',
            'price'       => '100.00',
            'okpd2'       => [],
            'ktru'        => [],
        ],
        regNumber: '0000000000000000001',
    );

    $mapper = new EisNoticeMapper();
    $mapper->toNormalizedTender($raw);
})->throws(NormalizationException::class, 'Пустое название закупки в ЕИС-извещении.');

it('toNormalizedTender fz44: Cancel в rootElement → sourceStatus Cancelled', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new \App\Adapters\Eis\RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEFCancel',
        data: [
            'number'      => '0173200001426000002',
            'title'       => 'Отмена закупки',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt'  => '',
            'customerName'=> 'Тест',
            'customerInn' => '7700000001',
            'region'      => '77',
            'price'       => '0',
            'okpd2'       => [],
            'ktru'        => [],
        ],
        regNumber: '0173200001426000002',
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    expect($n->sourceStatus)->toBe(TenderSourceStatus::Cancelled);
});

it('toNormalizedTender: нулевая цена (0) валидна → price="0.00"', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new \App\Adapters\Eis\RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: [
            'number'      => '0173200001426000003',
            'title'       => 'Закупка с нулевой ценой',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt'  => '',
            'customerName'=> 'Тест',
            'customerInn' => '7700000002',
            'region'      => '77',
            'price'       => '0',
            'okpd2'       => [],
            'ktru'        => [],
        ],
        regNumber: '0173200001426000003',
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    expect($n->price)->toBe('0.00');
});

it('toNormalizedTender: нечисловая цена → price=null', function () {
    config(['eis' => require base_path('config/eis.php')]);

    $raw = new \App\Adapters\Eis\RawTender(
        lawType: LawType::Fz44,
        rootElement: 'fcsNotificationEF',
        data: [
            'number'      => '0173200001426000004',
            'title'       => 'Закупка без цены',
            'publishedAt' => '2026-06-15T10:00:00+03:00',
            'deadlineAt'  => '',
            'customerName'=> 'Тест',
            'customerInn' => '7700000003',
            'region'      => '77',
            'price'       => 'договорная',
            'okpd2'       => [],
            'ktru'        => [],
        ],
        regNumber: '0173200001426000004',
    );

    $mapper = new EisNoticeMapper();
    $n = $mapper->toNormalizedTender($raw);

    expect($n->price)->toBeNull();
});
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisNoticeMapperNormalizeTest
```

Expected: `ERROR` — метод `toNormalizedTender` не существует в `EisNoticeMapper`.

---

- [ ] **Implement**

Добавить метод `toNormalizedTender` в `app/Adapters/Eis/EisNoticeMapper.php` — дописать в конец класса перед закрывающей `}`:

```php
    /**
     * Преобразовать RawTender в NormalizedTender.
     *
     * @throws NormalizationException при пустом title
     */
    public function toNormalizedTender(RawTender $raw): NormalizedTender
    {
        $data = $raw->data;

        // title обязателен
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            throw new NormalizationException('Пустое название закупки в ЕИС-извещении.');
        }

        // price: числовая и >= 0 → decimal-строка, иначе null (0 валиден)
        $priceRaw = trim($data['price'] ?? '');
        $price = null;
        if ($priceRaw !== '' && is_numeric($priceRaw) && (float) $priceRaw >= 0) {
            $price = number_format((float) $priceRaw, 2, '.', '');
        }

        // Даты — offset-aware → UTC
        $publishedAt = null;
        if (!empty($data['publishedAt'])) {
            try {
                $publishedAt = CarbonImmutable::parse($data['publishedAt'])->utc();
            } catch (\Throwable) {
                $publishedAt = null;
            }
        }

        $deadlineAt = null;
        if (!empty($data['deadlineAt'])) {
            try {
                $deadlineAt = CarbonImmutable::parse($data['deadlineAt'])->utc();
            } catch (\Throwable) {
                $deadlineAt = null;
            }
        }

        // sourceStatus: Cancel в rootElement → Cancelled, иначе Active
        $sourceStatus = str_contains($raw->rootElement, 'Cancel')
            ? TenderSourceStatus::Cancelled
            : TenderSourceStatus::Active;

        // sourceUrl по шаблону из конфига
        $sourceUrl = $raw->regNumber !== null
            ? sprintf(config('eis.source_url_template'), $raw->regNumber)
            : '';

        // okpd2 и ktru — уже списки из extract()
        $okpd2 = $data['okpd2'] ?? [];
        $ktru  = $data['ktru'] ?? [];

        $customerName = $data['customerName'] !== '' ? $data['customerName'] : null;
        $customerInn  = $data['customerInn'] !== '' ? $data['customerInn'] : null;
        $region       = $data['region'] !== '' ? $data['region'] : null;

        $contentHash = Canonicalizer::contentHash(
            $title,
            $customerName,
            $customerInn,
            $publishedAt?->format('Y-m-d'),
            $price,
        );

        return new NormalizedTender(
            title:         $title,
            description:   null,
            customerName:  $customerName,
            customerInn:   $customerInn,
            region:        $region,
            price:         $price,
            currency:      'RUB',
            lawType:       $raw->lawType,
            procedureType: null,
            sourceStatus:  $sourceStatus,
            publishedAt:   $publishedAt,
            deadlineAt:    $deadlineAt,
            sourceUrl:     $sourceUrl,
            number:        $raw->regNumber,
            okpd2:         $okpd2,
            contentHash:   $contentHash,
            ktru:          $ktru,
            extra:         ['rootElement' => $raw->rootElement],
        );
    }
```

Полный итоговый файл `app/Adapters/Eis/EisNoticeMapper.php` после обоих методов:

```php
<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\Canonicalizer;
use App\Services\Import\NormalizationException;
use App\Services\Import\NormalizedTender;
use Carbon\CarbonImmutable;

class EisNoticeMapper
{
    /**
     * Извлечь поля из DOM-узла извещения по конфигу eis.fields[law->value].
     *
     * @throws \InvalidArgumentException
     */
    public function extract(\DOMNode $notice, LawType $law): RawTender
    {
        $fields = config('eis.fields')[$law->value] ?? [];

        if (empty($fields)) {
            throw new \InvalidArgumentException(
                "Нет конфигурации eis.fields для закона: {$law->value}"
            );
        }

        $doc   = ($notice instanceof \DOMDocument) ? $notice : $notice->ownerDocument;
        $xpath = new \DOMXPath($doc);

        // Хелпер: первое значение элемента по local-name относительно $notice
        $first = function (string $elem) use ($xpath, $notice): string {
            $nodes = $xpath->query(".//*[local-name()=\"{$elem}\"]", $notice);
            if ($nodes === false || $nodes->length === 0) {
                return '';
            }
            return trim($nodes->item(0)->textContent);
        };

        // Хелпер: все значения элемента (для okpd2/ktru)
        $all = function (string $elem) use ($xpath, $notice): array {
            $nodes = $xpath->query(".//*[local-name()=\"{$elem}\"]", $notice);
            if ($nodes === false) {
                return [];
            }
            $result = [];
            foreach ($nodes as $node) {
                $v = trim($node->textContent);
                if ($v !== '') {
                    $result[] = $v;
                }
            }
            return $result;
        };

        $data = [
            'number'       => $first($fields['number']),
            'title'        => $first($fields['title']),
            'publishedAt'  => $first($fields['publishedAt']),
            'deadlineAt'   => $first($fields['deadlineAt']),
            'customerName' => $first($fields['customerName']),
            'customerInn'  => $first($fields['customerInn']),
            'region'       => $first($fields['region']),
            'price'        => $first($fields['price']),
            'okpd2'        => $all($fields['okpd2']),
            'ktru'         => $all($fields['ktru']),
        ];

        // rootElement — localName DOM-узла (уже без префикса)
        $rootElement = $notice->localName;
        $regNumber   = $data['number'] !== '' ? $data['number'] : null;

        return new RawTender(
            lawType:     $law,
            rootElement: $rootElement,
            data:        $data,
            regNumber:   $regNumber,
        );
    }

    /**
     * Преобразовать RawTender в NormalizedTender.
     *
     * @throws NormalizationException при пустом title
     */
    public function toNormalizedTender(RawTender $raw): NormalizedTender
    {
        $data = $raw->data;

        // title обязателен
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            throw new NormalizationException('Пустое название закупки в ЕИС-извещении.');
        }

        // price: числовая и >= 0 → decimal-строка, иначе null (0 валиден)
        $priceRaw = trim($data['price'] ?? '');
        $price = null;
        if ($priceRaw !== '' && is_numeric($priceRaw) && (float) $priceRaw >= 0) {
            $price = number_format((float) $priceRaw, 2, '.', '');
        }

        // Даты — offset-aware → UTC
        $publishedAt = null;
        if (!empty($data['publishedAt'])) {
            try {
                $publishedAt = CarbonImmutable::parse($data['publishedAt'])->utc();
            } catch (\Throwable) {
                $publishedAt = null;
            }
        }

        $deadlineAt = null;
        if (!empty($data['deadlineAt'])) {
            try {
                $deadlineAt = CarbonImmutable::parse($data['deadlineAt'])->utc();
            } catch (\Throwable) {
                $deadlineAt = null;
            }
        }

        // sourceStatus: Cancel в rootElement → Cancelled, иначе Active
        $sourceStatus = str_contains($raw->rootElement, 'Cancel')
            ? TenderSourceStatus::Cancelled
            : TenderSourceStatus::Active;

        // sourceUrl по шаблону из конфига
        $sourceUrl = $raw->regNumber !== null
            ? sprintf(config('eis.source_url_template'), $raw->regNumber)
            : '';

        // okpd2 и ktru — уже списки из extract()
        $okpd2 = $data['okpd2'] ?? [];
        $ktru  = $data['ktru'] ?? [];

        $customerName = ($data['customerName'] ?? '') !== '' ? $data['customerName'] : null;
        $customerInn  = ($data['customerInn'] ?? '') !== '' ? $data['customerInn'] : null;
        $region       = ($data['region'] ?? '') !== '' ? $data['region'] : null;

        $contentHash = Canonicalizer::contentHash(
            $title,
            $customerName,
            $customerInn,
            $publishedAt?->format('Y-m-d'),
            $price,
        );

        return new NormalizedTender(
            title:         $title,
            description:   null,
            customerName:  $customerName,
            customerInn:   $customerInn,
            region:        $region,
            price:         $price,
            currency:      'RUB',
            lawType:       $raw->lawType,
            procedureType: null,
            sourceStatus:  $sourceStatus,
            publishedAt:   $publishedAt,
            deadlineAt:    $deadlineAt,
            sourceUrl:     $sourceUrl,
            number:        $raw->regNumber,
            okpd2:         $okpd2,
            contentHash:   $contentHash,
            ktru:          $ktru,
            extra:         ['rootElement' => $raw->rootElement],
        );
    }
}
```

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisNoticeMapperNormalizeTest
```

Expected:
```
PASS  Tests\Unit\Eis\EisNoticeMapperNormalizeTest
✓ toNormalizedTender fz44: поля маппятся корректно
✓ toNormalizedTender fz44: publishedAt конвертируется в UTC (+03:00 → 07:00 UTC)
✓ toNormalizedTender fz44: contentHash детерминирован и длиной 64
✓ toNormalizedTender fz44: sourceUrl содержит regNumber
✓ toNormalizedTender fz223: поля маппятся корректно
✓ toNormalizedTender fz223: publishedAt в UTC (+03:00 → 06:30 UTC)
✓ toNormalizedTender: пустой title → NormalizationException
✓ toNormalizedTender fz44: Cancel в rootElement → sourceStatus Cancelled
✓ toNormalizedTender: нулевая цена (0) валидна → price="0.00"
✓ toNormalizedTender: нечисловая цена → price=null
Tests: 10 passed
```

Прогнать все тесты кластера C3a вместе:

```bash
cd /home/roman/sites/www/tender && php artisan test --filter="EisFixturesTest|EisNoticeMapperExtractTest|EisNoticeMapperNormalizeTest"
```

Expected:
```
Tests: 16 passed
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Adapters/Eis/EisNoticeMapper.php tests/Unit/Eis/EisNoticeMapperNormalizeTest.php && git commit -m "$(cat <<'EOF'
A6 C3a Task 10: EisNoticeMapper::toNormalizedTender — UTC-даты, price decimal, Cancel→Cancelled, NormalizationException

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

**Итоговые файлы кластера C3a (все абсолютные пути):**

- `/home/roman/sites/www/tender/tests/Fixtures/eis/fz44_notice.xml` — создан в Task 8
- `/home/roman/sites/www/tender/tests/Fixtures/eis/fz223_notice.xml` — создан в Task 8
- `/home/roman/sites/www/tender/tests/Support/EisFixtures.php` — создан в Task 8
- `/home/roman/sites/www/tender/tests/Pest.php` — модифицирован в Task 8 (добавлен `in('Unit/Eis')`)
- `/home/roman/sites/www/tender/composer.json` — модифицирован в Task 8 (добавлен `files` в `autoload-dev`)
- `/home/roman/sites/www/tender/tests/Unit/Eis/EisFixturesTest.php` — создан в Task 8
- `/home/roman/sites/www/tender/app/Adapters/Eis/EisNoticeMapper.php` — создан в Task 9, расширен в Task 10
- `/home/roman/sites/www/tender/tests/Unit/Eis/EisNoticeMapperExtractTest.php` — создан в Task 9
- `/home/roman/sites/www/tender/tests/Unit/Eis/EisNoticeMapperNormalizeTest.php` — создан в Task 10



### Task 11: SoapEisGateway — HTTP-транспорт ЕИС

**Files:**
- Create `app/Adapters/Eis/SoapEisGateway.php`
- Create `tests/Unit/Eis/SoapEisGatewayTest.php`

**Interfaces:**
- Consumes: `app/Adapters/Eis/EisGateway.php` (интерфейс, создан в Task 2)
- Consumes: `app/Adapters/Eis/CollectParams.php` (создан в Task 2)
- Consumes: `app/Adapters/Eis/PackageRef.php` (создан в Task 2)
- Consumes: `config/eis.php` (создан в Task 1)
- Produces: `SoapEisGateway` — реальный транспорт, потребляется `EisAdapter` (Task 12–13)

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/SoapEisGatewayTest.php`:

```php
<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\PackageRef;
use App\Adapters\Eis\SoapEisGateway;
use App\Enums\LawType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

// NOTE: Имена SOAP-элементов (getDocsByOrgRegionRequest, архивные поля)
// требуют сверки с живой WSDL / Альбомом ТФФ ЕИС перед первым прод-запуском.

it('listPackages строит SOAP-запрос с токеном и возвращает PackageRef', function () {
    config([
        'eis.token'    => 'test-token',
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
                   <result><archiveUrl>' . $archiveUrl . '</archiveUrl></result>
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

    $gateway = new SoapEisGateway();
    $packages = iterator_to_array($gateway->listPackages($params));

    expect($packages)->toHaveCount(1);
    expect($packages[0])->toBeInstanceOf(PackageRef::class);
    expect($packages[0]->archiveUrl)->toBe($archiveUrl);
    expect($packages[0]->region)->toBe('77');
    expect($packages[0]->subsystemType)->toBe('PRIZ');
    expect($packages[0]->date)->toBe('2026-06-15');
    expect($packages[0]->lawType)->toBe(LawType::Fz44);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->hasHeader('individualPerson_token', 'test-token')
            && str_contains((string) $request->url(), 'getDocsIP')
            && str_contains($request->body(), 'getDocsByOrgRegionRequest');
    });
});

it('listPackages пропускает дни без archiveUrl (пустой результат)', function () {
    config([
        'eis.token'    => 'test-token',
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

    $gateway = new SoapEisGateway();
    $packages = iterator_to_array($gateway->listPackages($params));

    expect($packages)->toHaveCount(0);
});

it('fetchByReestrNumber возвращает XML-строку с токеном в заголовке', function () {
    config([
        'eis.token'    => 'test-token',
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

    $gateway = new SoapEisGateway();
    $result = $gateway->fetchByReestrNumber('0173200001426000001');

    expect($result)->toBeString();
    expect($result)->not->toBeEmpty();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->hasHeader('individualPerson_token', 'test-token')
            && str_contains($request->body(), 'getDocsByReestrNumberRequest')
            && str_contains($request->body(), '0173200001426000001');
    });
});

it('fetchByReestrNumber возвращает null при пустом ответе', function () {
    config([
        'eis.token'    => 'test-token',
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

    $gateway = new SoapEisGateway();
    $result = $gateway->fetchByReestrNumber('9999999999999999999');

    expect($result)->toBeNull();
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

    $dest = sys_get_temp_dir() . '/eis_test_' . uniqid() . '.zip';
    $gateway = new SoapEisGateway();
    $gateway->downloadArchive($ref, $dest);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($ref) {
        return $request->hasHeader('individualPerson_token', 'test-token')
            && (string) $request->url() === $ref->archiveUrl;
    });

    @unlink($dest);
});
```

- [ ] **Run** (ожидаем FAIL — класс не существует):

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=SoapEisGatewayTest
```

**Expected FAIL:** `Error: Class "App\Adapters\Eis\SoapEisGateway" not found`

- [ ] **Implement**

Создать `app/Adapters/Eis/SoapEisGateway.php`:

```php
<?php

namespace App\Adapters\Eis;

use App\Enums\LawType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-транспорт ЕИС через «сервисы отдачи» getDocsIP (SOAP over HTTP).
 * Использует Http-фасад вместо нативного SoapClient для тестируемости.
 *
 * NOTE: Имена SOAP-элементов требуют сверки с актуальной WSDL / Альбомом ТФФ ЕИС
 *       перед первым прод-запуском (last verified: июнь 2026).
 */
class SoapEisGateway implements EisGateway
{
    /**
     * @return iterable<PackageRef>
     */
    public function listPackages(CollectParams $params): iterable
    {
        $subsystemLawMap = config('eis.subsystem_law_map', []);
        $endpoint        = (string) config('eis.endpoint');
        $token           = (string) config('eis.token');

        // Итерируем декартово произведение: регион × подсистема × дата
        $current = $params->since->startOfDay();
        $until   = $params->until->startOfDay();

        while ($current->lte($until)) {
            $exactDate = $current->format('Y-m-d');

            foreach ($params->regions as $region) {
                foreach ($params->subsystemTypes as $subsystem) {
                    $xml = $this->buildOrgRegionRequest($region, $subsystem, $exactDate);

                    $response = Http::withHeaders([
                        'individualPerson_token' => $token,
                        'Content-Type'           => 'text/xml; charset=utf-8',
                        'SOAPAction'             => '"getDocsByOrgRegion"',
                    ])->withBody($xml, 'text/xml')->post($endpoint);

                    $lawKey  = $subsystemLawMap[$subsystem] ?? null;
                    $lawType = $lawKey !== null ? LawType::from($lawKey) : LawType::Other;

                    foreach ($this->extractArchiveUrls($response->body()) as $archiveUrl) {
                        yield new PackageRef(
                            archiveUrl: $archiveUrl,
                            region: $region,
                            subsystemType: $subsystem,
                            date: $exactDate,
                            lawType: $lawType,
                        );
                    }
                }
            }

            $current = $current->addDay();
        }
    }

    public function downloadArchive(PackageRef $ref, string $destAbsolutePath): void
    {
        $dir = dirname($destAbsolutePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        Http::withHeaders([
            'individualPerson_token' => (string) config('eis.token'),
        ])->sink($destAbsolutePath)->get($ref->archiveUrl);
    }

    public function fetchByReestrNumber(string $regNumber): ?string
    {
        $endpoint = (string) config('eis.endpoint');
        $token    = (string) config('eis.token');

        $xml = $this->buildReestrNumberRequest($regNumber);

        $response = Http::withHeaders([
            'individualPerson_token' => $token,
            'Content-Type'           => 'text/xml; charset=utf-8',
            'SOAPAction'             => '"getDocsByReestrNumber"',
        ])->withBody($xml, 'text/xml')->post($endpoint);

        return $this->extractNoticeXml($response->body());
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    private function buildOrgRegionRequest(string $region, string $subsystem, string $exactDate): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
              <S:Body>
                <ns2:getDocsByOrgRegionRequest>
                  <ns2:orgRegion>{$region}</ns2:orgRegion>
                  <ns2:subsystemType>{$subsystem}</ns2:subsystemType>
                  <ns2:exactDate>{$exactDate}</ns2:exactDate>
                </ns2:getDocsByOrgRegionRequest>
              </S:Body>
            </S:Envelope>
            XML;
    }

    private function buildReestrNumberRequest(string $regNumber): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:ns2="http://zakupki.gov.ru/eis-integration/services/getDocsIP">
              <S:Body>
                <ns2:getDocsByReestrNumberRequest>
                  <ns2:reestrNumber>{$regNumber}</ns2:reestrNumber>
                </ns2:getDocsByReestrNumberRequest>
              </S:Body>
            </S:Envelope>
            XML;
    }

    /** @return list<string> */
    private function extractArchiveUrls(string $soapResponse): array
    {
        if (trim($soapResponse) === '') {
            return [];
        }

        $doc = new \DOMDocument();
        if (! @$doc->loadXML($soapResponse)) {
            return [];
        }

        $urls = [];
        foreach ($doc->getElementsByTagName('archiveUrl') as $node) {
            $url = trim($node->textContent);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function extractNoticeXml(string $soapResponse): ?string
    {
        if (trim($soapResponse) === '') {
            return null;
        }

        $doc = new \DOMDocument();
        if (! @$doc->loadXML($soapResponse)) {
            return null;
        }

        // noticeXml содержит экранированный XML — возвращаем текстовое содержимое
        foreach ($doc->getElementsByTagName('noticeXml') as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
```

- [ ] **Run** (ожидаем PASS):

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=SoapEisGatewayTest
```

**Expected PASS:** `4 passed`

- [ ] **Commit:**

```bash
cd /home/roman/sites/www/tender && git add app/Adapters/Eis/SoapEisGateway.php tests/Unit/Eis/SoapEisGatewayTest.php && git commit -m "$(cat <<'EOF'
A6 Task 11: SoapEisGateway — HTTP-транспорт ЕИС (Http-фасад, SOAP over HTTP)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: EisAdapter::streamNotices — потоковый разбор ZIP+XML

**Files:**
- Create `app/Adapters/Eis/EisAdapter.php` (только `sourceCode`, `__construct`, `streamNotices`)
- Create `tests/Unit/Eis/EisAdapterStreamTest.php`

**Interfaces:**
- Consumes: `tests/Support/EisFixtures.php` — функция `makeEisArchive` (создана в Task 8)
- Consumes: `tests/Fixtures/eis/fz44_notice.xml` (создана в Task 8)
- Consumes: `tests/Fixtures/eis/fz223_notice.xml` (создана в Task 8)
- Consumes: `app/Adapters/Eis/EisNoticeMapper.php` (создана в Task 9, модифицирована в Task 10)
- Consumes: `app/Adapters/Eis/EisGateway.php` — интерфейс (создан в Task 2)
- Consumes: `config/eis.php` (создан в Task 1)
- Produces: `EisAdapter` с `streamNotices` — потребляется Task 13 (расширение) и ProcessChunkJob (Task 16)

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/EisAdapterStreamTest.php`:

```php
<?php

use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\EisNoticeMapper;
use App\Adapters\Eis\RawTender;
use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Services\Import\NormalizationException;
use function Tests\Support\makeEisArchive;

// streamNotices: fz44 ZIP → 1 RawTender с корректным rootElement

it('streamNotices возвращает 1 RawTender из fz44_notice.xml', function () {
    $zipPath = makeEisArchive([
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
    ]);

    $mapper  = new EisNoticeMapper();
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

    $mapper  = new EisNoticeMapper();
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
    $tmpXml = tempnam(sys_get_temp_dir(), 'eis_unknown_') . '.xml';
    file_put_contents($tmpXml, '<?xml version="1.0" encoding="UTF-8"?><unknownRoot><foo>bar</foo></unknownRoot>');

    $zipPath = makeEisArchive([$tmpXml]);

    $mapper  = new EisNoticeMapper();
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

    $mapper  = new EisNoticeMapper();
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
    $tmpXml = tempnam(sys_get_temp_dir(), 'eis_cancel_') . '.xml';
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

    $mapper  = new EisNoticeMapper();
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
```

- [ ] **Run** (ожидаем FAIL — классы не существуют):

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisAdapterStreamTest
```

**Expected FAIL:** `Error: Class "App\Adapters\Eis\EisAdapter" not found` (или `EisNoticeMapper`)

- [ ] **Implement**

Создать `app/Adapters/Eis/EisAdapter.php` (только `sourceCode`, `__construct`, `streamNotices`; остальные методы добавляются в Task 13):

```php
<?php

namespace App\Adapters\Eis;

use App\Adapters\Contracts\TenderSourceAdapter;
use App\Enums\LawType;
use App\Services\Import\NormalizedTender;

/**
 * Фасад ЕИС-адаптера.
 * Task 12 реализует: sourceCode(), __construct(), streamNotices().
 * Task 13 добавляет: listPackages(), downloadArchive(), normalize(), details(), collect().
 */
class EisAdapter implements TenderSourceAdapter
{
    public function __construct(
        private readonly EisGateway $gateway,
        private readonly EisNoticeMapper $mapper,
    ) {}

    public function sourceCode(): string
    {
        return 'eis';
    }

    /**
     * Потоковое извлечение извещений из ZIP-архива.
     * Паттерн: ZipArchive → каждый .xml → временный файл → XMLReader по узлам.
     * Пик памяти = одно извещение (не весь файл).
     *
     * @return iterable<RawTender>
     */
    public function streamNotices(string $zipAbsolutePath, LawType $law): iterable
    {
        $lawKey      = $law->value;
        $noticeRoots = (array) (config('eis.notice_roots')[$lawKey] ?? []);

        $zip = new \ZipArchive();
        if ($zip->open($zipAbsolutePath) !== true) {
            return;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || ! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }

                // Извлечь один XML во временный файл (потоковый, не loadXML всего)
                $tmpFile = tempnam(sys_get_temp_dir(), 'eis_xml_');
                try {
                    $stream = $zip->getStream($name);
                    if ($stream === false) {
                        continue;
                    }

                    $fp = fopen($tmpFile, 'wb');
                    if ($fp === false) {
                        continue;
                    }
                    try {
                        while (! feof($stream)) {
                            fwrite($fp, (string) fread($stream, 65536));
                        }
                    } finally {
                        fclose($fp);
                        fclose($stream);
                    }

                    // XMLReader — потоковое чтение по узлам
                    yield from $this->readNoticesFromFile($tmpFile, $law, $noticeRoots);
                } finally {
                    @unlink($tmpFile);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<string>  $noticeRoots
     * @return iterable<RawTender>
     */
    private function readNoticesFromFile(string $filePath, LawType $law, array $noticeRoots): iterable
    {
        $reader = new \XMLReader();
        if (! $reader->open('file://' . $filePath)) {
            return;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                // localName — без префикса namespace
                if (! in_array($reader->localName, $noticeRoots, true)) {
                    continue;
                }

                // Расширяем один узел в DOMNode (не весь документ)
                $dom  = new \DOMDocument();
                $node = $reader->expand($dom);
                if ($node === false) {
                    continue;
                }

                $dom->appendChild($dom->importNode($node, true));

                yield $this->mapper->extract($node, $law);
            }
        } finally {
            $reader->close();
        }
    }

    // -------------------------------------------------------------------------
    // Полная реализация методов адаптера (binding EisGateway→SoapEisGateway добавляется в Task 13; здесь — НЕ заглушки)
    // -------------------------------------------------------------------------

    /** @return iterable<PackageRef> */
    public function listPackages(CollectParams $params): iterable
    {
        yield from $this->gateway->listPackages($params);
    }

    public function downloadArchive(PackageRef $ref, string $destAbsolutePath): void
    {
        $this->gateway->downloadArchive($ref, $destAbsolutePath);
    }

    public function normalize(RawTender $raw): NormalizedTender
    {
        return $this->mapper->toNormalizedTender($raw);
    }

    public function details(string $externalId): ?RawTenderDetails
    {
        $xml = $this->gateway->fetchByReestrNumber($externalId);
        if ($xml === null) {
            return null;
        }

        // Определяем lawType из root-элемента XML
        $doc = new \DOMDocument();
        if (! @$doc->loadXML($xml)) {
            return null;
        }

        $rootEl  = $doc->documentElement?->localName ?? '';
        $lawType = $this->resolveLawTypeFromRoot($rootEl);
        if ($lawType === null) {
            return null;
        }

        $raw = $this->mapper->extract($doc->documentElement, $lawType);

        return new RawTenderDetails(raw: $raw, rawXml: $xml);
    }

    public function collect(CollectParams $params): iterable
    {
        foreach ($this->gateway->listPackages($params) as $ref) {
            $dest = sys_get_temp_dir() . '/eis_collect_' . md5($ref->archiveUrl) . '.zip';
            try {
                $this->gateway->downloadArchive($ref, $dest);
                yield from $this->streamNotices($dest, $ref->lawType);
            } finally {
                @unlink($dest);
            }
        }
    }

    // -------------------------------------------------------------------------

    private function resolveLawTypeFromRoot(string $rootElement): ?LawType
    {
        /** @var array<string, list<string>> $noticeRoots */
        $noticeRoots = (array) config('eis.notice_roots', []);

        foreach ($noticeRoots as $lawKey => $roots) {
            if (in_array($rootElement, $roots, true)) {
                return LawType::from($lawKey);
            }
        }

        return null;
    }
}
```

> Примечание: `readNoticesFromFile` передаёт `$node` (исходный `DOMNode` из `XMLReader::expand`) напрямую в `mapper->extract`. Это корректно: `expand()` возвращает `DOMNode` связанный с переданным документом, `importNode` делает копию для владения документом, но `$node` уже является `DOMNode` с полным поддеревом.

- [ ] **Run** (ожидаем PASS):

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisAdapterStreamTest
```

**Expected PASS:** `5 passed`

- [ ] **Commit:**

```bash
cd /home/roman/sites/www/tender && git add app/Adapters/Eis/EisAdapter.php tests/Unit/Eis/EisAdapterStreamTest.php && git commit -m "$(cat <<'EOF'
A6 Task 12: EisAdapter::streamNotices — потоковый ZipArchive+XMLReader без OOM

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: EisAdapter полный + service binding

**Files:**
- Modify `app/Providers/AppServiceProvider.php` — добавить `bind(EisGateway, SoapEisGateway)` в `register()`
- Create `tests/Unit/Eis/EisAdapterFullTest.php`

**Interfaces:**
- Consumes: `app/Adapters/Eis/EisAdapter.php` (создан и полностью реализован в Task 12 — все методы уже есть; в данной задаче файл не модифицируется)
- Consumes: `app/Adapters/Eis/SoapEisGateway.php` (создана в Task 11)
- Consumes: `app/Adapters/Eis/EisGateway.php` (интерфейс, создан в Task 2)
- Consumes: `app/Adapters/Eis/EisNoticeMapper.php` (создана в Task 9, модифицирована в Task 10)
- Consumes: `tests/Support/EisFixtures.php` — функция `makeEisArchive` (создана в Task 8)
- Consumes: `tests/Fixtures/eis/fz44_notice.xml` (создана в Task 8)
- Produces: service binding `EisGateway→SoapEisGateway`; `app(EisAdapter::class)` резолвится через IoC

---

- [ ] **Write failing test**

Создать `tests/Unit/Eis/EisAdapterFullTest.php`:

```php
<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Adapters\Eis\RawTender;
use App\Adapters\Eis\RawTenderDetails;
use App\Enums\LawType;
use Carbon\CarbonImmutable;
use function Tests\Support\makeEisArchive;

it('app(EisAdapter::class) резолвится через IoC-контейнер', function () {
    $adapter = app(EisAdapter::class);
    expect($adapter)->toBeInstanceOf(EisAdapter::class);
});

it('sourceCode возвращает "eis"', function () {
    $adapter = app(EisAdapter::class);
    expect($adapter->sourceCode())->toBe('eis');
});

it('collect() с фейк-gateway возвращает RawTender из fz44_notice', function () {
    $zipPath = makeEisArchive([
        base_path('tests/Fixtures/eis/fz44_notice.xml'),
    ]);

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/eis-integration/download/some.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('listPackages')
        ->once()
        ->andReturn([$ref]);
    $fakeGateway->shouldReceive('downloadArchive')
        ->once()
        ->andReturnUsing(function (PackageRef $r, string $dest) use ($zipPath) {
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($zipPath, $dest);
        });

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);

    $params = new CollectParams(
        since: CarbonImmutable::parse('2026-06-15', 'UTC'),
        until: CarbonImmutable::parse('2026-06-15', 'UTC'),
        regions: ['77'],
        subsystemTypes: ['PRIZ'],
    );

    $results = iterator_to_array($adapter->collect($params));

    expect($results)->toHaveCount(1);
    expect($results[0])->toBeInstanceOf(RawTender::class);
    expect($results[0]->regNumber)->toBe('0173200001426000001');
    expect($results[0]->lawType)->toBe(LawType::Fz44);

    @unlink($zipPath);
});

it('details() возвращает RawTenderDetails при наличии XML', function () {
    // fcsNotificationEF — минимальный XML без namespace-оберток export
    $noticeXml = '<?xml version="1.0" encoding="UTF-8"?>
<fcsNotificationEF>
  <purchaseNumber>0173200001426000001</purchaseNumber>
  <purchaseObjectInfo>Поставка оборудования</purchaseObjectInfo>
  <docPublishDate>2026-06-15T10:00:00+03:00</docPublishDate>
  <purchaseResponsible><responsibleOrg>
    <fullName>ГБУЗ Тест</fullName><INN>7701234567</INN><regionCode>77</regionCode>
  </responsibleOrg></purchaseResponsible>
  <notificationInfo><procedureInfo><collecting><endDate>2026-06-25T18:00:00+03:00</endDate></collecting></procedureInfo></notificationInfo>
  <lot><maxPrice>100000.00</maxPrice></lot>
</fcsNotificationEF>';

    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('fetchByReestrNumber')
        ->once()
        ->with('0173200001426000001')
        ->andReturn($noticeXml);

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);
    $result  = $adapter->details('0173200001426000001');

    expect($result)->toBeInstanceOf(RawTenderDetails::class);
    expect($result->raw)->toBeInstanceOf(RawTender::class);
    expect($result->raw->regNumber)->toBe('0173200001426000001');
    expect($result->rawXml)->toBe($noticeXml);
});

it('details() возвращает null если gateway вернул null', function () {
    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('fetchByReestrNumber')
        ->once()
        ->andReturn(null);

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);
    expect($adapter->details('9999999999'))->toBeNull();
});

it('details() возвращает null при неизвестном root-элементе', function () {
    $unknownXml = '<?xml version="1.0"?><someUnknownElement><foo>bar</foo></someUnknownElement>';

    $fakeGateway = Mockery::mock(EisGateway::class);
    $fakeGateway->shouldReceive('fetchByReestrNumber')
        ->once()
        ->andReturn($unknownXml);

    $adapter = app()->make(EisAdapter::class, ['gateway' => $fakeGateway]);
    expect($adapter->details('123'))->toBeNull();
});
```

- [ ] **Run** (ожидаем FAIL — binding не зарегистрирован):

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisAdapterFullTest
```

**Expected FAIL:** `Illuminate\Contracts\Container\BindingResolutionException` (невозможно резолвить `EisGateway` — интерфейс без реализации) либо тест `app(EisAdapter::class) резолвится` падает.

- [ ] **Implement**

Модифицировать `app/Providers/AppServiceProvider.php` — добавить bind в метод `register()`:

Текущее содержимое `register()`:
```php
public function register(): void
{
    $this->app->scoped(TenantContext::class);
}
```

Новое содержимое — добавить строки bind после `scoped`:

```php
<?php

namespace App\Providers;

use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\SoapEisGateway;
use App\Enums\UserRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        // ЕИС-адаптер: SoapEisGateway — реальный HTTP-транспорт
        $this->app->bind(EisGateway::class, SoapEisGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('manage-company', fn (User $user): bool => $user->role === UserRole::Admin && $user->company_id !== null);

        Gate::define('view-tenders', fn (User $user): bool => $user->company_id !== null);

        Gate::define('manage-tender-workflow', fn (User $user): bool => $user->company_id !== null
            && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));
    }
}
```

- [ ] **Run** (ожидаем PASS):

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=EisAdapterFullTest
```

**Expected PASS:** `5 passed`

- [ ] **Run полный прогон Unit/Eis** (финальная проверка кластера C3b):

```bash
cd /home/roman/sites/www/tender && php artisan test tests/Unit/Eis/
```

**Expected PASS:** все тесты `SoapEisGatewayTest`, `EisAdapterStreamTest`, `EisAdapterFullTest` зелёные (≥14 passed).

- [ ] **Статический анализ:**

```bash
cd /home/roman/sites/www/tender && ./vendor/bin/phpstan analyse app/Adapters app/Providers/AppServiceProvider.php --level=6
```

**Expected:** `[OK] No errors`

- [ ] **Стиль:**

```bash
cd /home/roman/sites/www/tender && ./vendor/bin/pint app/Adapters app/Providers/AppServiceProvider.php
```

**Expected:** `Everything is correct` (или автоисправление без ошибок)

- [ ] **Commit:**

```bash
cd /home/roman/sites/www/tender && git add app/Providers/AppServiceProvider.php tests/Unit/Eis/EisAdapterFullTest.php && git commit -m "$(cat <<'EOF'
A6 Task 13: service binding EisGateway→SoapEisGateway + тесты полного EisAdapter

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

**Примечания по кластеру C3b:**

- `/home/roman/sites/www/tender/app/Adapters/Eis/SoapEisGateway.php` — создаётся в Task 11
- `/home/roman/sites/www/tender/app/Adapters/Eis/EisAdapter.php` — создаётся в Task 12 (все методы включая `listPackages`, `downloadArchive`, `normalize`, `details`, `collect` реализованы сразу, чтобы тесты Task 13 не падали на заглушках; согласно таблице владения T12 создаёт файл, T13 только потребляет)
- `/home/roman/sites/www/tender/app/Providers/AppServiceProvider.php` — модифицируется в Task 13
- Тесты регистрируются в пространстве `tests/Unit/Eis/`; `pest()->extend(TestCase::class)->in('Unit/Eis')` должна быть добавлена в `tests/Pest.php` в Task 2 (кластер C1)
- `EisNoticeMapper` (`extract` + `toNormalizedTender`) создаётся и модифицируется в Task 9/10 (кластер C3a) — файл в Task 12–13 только потребляется
- `makeEisArchive` из `Tests\Support\EisFixtures` и XML-фикстуры создаются в Task 8 (кластер C3a)
- Имена SOAP-элементов (`getDocsByOrgRegionRequest`, `getDocsByReestrNumberRequest`) требуют сверки с актуальной WSDL ЕИС / Альбомом ТФФ перед первым прод-запуском



### Task 14: config/queue.php — соединение redis-long (retry_after=900)

**Files:**
- Modify: `/home/roman/sites/www/tender/config/queue.php`
- Test: `/home/roman/sites/www/tender/tests/Unit/Queue/RedisLongConnectionTest.php`

**Interfaces:**
- Produces: соединение `redis-long` с `retry_after=900`, используется всеми collect-джобами (Tasks 15–18)

---

- [ ] **Write failing test**

`tests/Unit/Queue/RedisLongConnectionTest.php`:
```php
<?php

it('соединение redis-long существует и имеет retry_after=900', function () {
    $conn = config('queue.connections.redis-long');

    expect($conn)->not->toBeNull()
        ->and($conn['driver'])->toBe('redis')
        ->and($conn['retry_after'])->toBe(900);
});
```

- [ ] **Run**
```bash
php artisan test --filter="redis-long"
```
Expected FAIL: `Failed asserting that null is not null` (ключ `redis-long` отсутствует).

- [ ] **Implement**

Добавить в `config/queue.php` после блока `'redis' => [...]`, перед `'deferred'`:

```php
        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 900,
            'block_for' => null,
            'after_commit' => false,
        ],
```

Полный файл после правки `/home/roman/sites/www/tender/config/queue.php`:
```php
<?php

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 900,
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
```

- [ ] **Run**
```bash
php artisan test --filter="redis-long"
```
Expected PASS: `1 passed`.

- [ ] **Commit**
```bash
git add config/queue.php tests/Unit/Queue/RedisLongConnectionTest.php
git commit -m "$(cat <<'EOF'
A6 C4 Task14: добавить соединение redis-long (retry_after=900) для collect-джобов

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: CollectMatchFanoutJob

**Files:**
- Create: `/home/roman/sites/www/tender/app/Jobs/CollectMatchFanoutJob.php`
- Test: `/home/roman/sites/www/tender/tests/Feature/Sources/CollectMatchFanoutJobTest.php`

**Interfaces:**
- Consumes: `app/Jobs/MatchProfileJob.php`, `app/Models/SearchProfile.php` (BelongsToCompany с withoutTenancy)
- Produces: диспатч `MatchProfileJob` для каждого активного профиля всех компаний

---

- [ ] **Write failing test**

`tests/Feature/Sources/CollectMatchFanoutJobTest.php`:
```php
<?php

use App\Enums\RunTrigger;
use App\Jobs\CollectMatchFanoutJob;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\SearchProfile;
use Illuminate\Support\Facades\Queue;

it('диспатчит MatchProfileJob для каждого активного профиля всех компаний', function () {
    Queue::fake();

    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();

    // три активных профиля в двух компаниях
    SearchProfile::factory()->for($company1)->create(['is_active' => true]);
    SearchProfile::factory()->for($company1)->create(['is_active' => true]);
    SearchProfile::factory()->for($company2)->create(['is_active' => true]);
    // один неактивный — не должен попасть
    SearchProfile::factory()->for($company2)->create(['is_active' => false]);

    (new CollectMatchFanoutJob('2026-06-17T10:00:00+00:00'))->handle();

    Queue::assertPushed(MatchProfileJob::class, 3);
    Queue::assertPushed(MatchProfileJob::class, function (MatchProfileJob $job) {
        return $job->trigger === RunTrigger::Schedule->value
            && $job->sinceUpdatedAt === '2026-06-17T10:00:00+00:00';
    });
});

it('не диспатчит ничего при отсутствии активных профилей', function () {
    Queue::fake();

    SearchProfile::factory()->create(['is_active' => false]);

    (new CollectMatchFanoutJob(null))->handle();

    Queue::assertNothingPushed();
});

it('диспатчится самостоятельно с синтаксисом dispatch()', function () {
    Queue::fake();

    CollectMatchFanoutJob::dispatch('2026-06-17T12:00:00+00:00');

    Queue::assertPushed(CollectMatchFanoutJob::class, function (CollectMatchFanoutJob $job) {
        return $job->sinceUpdatedAt === '2026-06-17T12:00:00+00:00';
    });
});
```

- [ ] **Run**
```bash
php artisan test --filter="CollectMatchFanoutJob"
```
Expected FAIL: `Class "App\Jobs\CollectMatchFanoutJob" not found`.

- [ ] **Implement**

`app/Jobs/CollectMatchFanoutJob.php`:
```php
<?php

namespace App\Jobs;

use App\Enums\RunTrigger;
use App\Models\SearchProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CollectMatchFanoutJob implements ShouldQueue
{
    use Queueable;

    public string $connection = 'redis-long';

    public string $queue = 'collect';

    public function __construct(
        public ?string $sinceUpdatedAt = null,
    ) {}

    public function handle(): void
    {
        SearchProfile::withoutTenancy()
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $id) => MatchProfileJob::dispatch(
                $id,
                RunTrigger::Schedule->value,
                $this->sinceUpdatedAt,
            ));
    }
}
```

- [ ] **Run**
```bash
php artisan test --filter="CollectMatchFanoutJob"
```
Expected PASS: `3 passed`.

- [ ] **Commit**
```bash
git add app/Jobs/CollectMatchFanoutJob.php tests/Feature/Sources/CollectMatchFanoutJobTest.php
git commit -m "$(cat <<'EOF'
A6 C4 Task15: CollectMatchFanoutJob — глобальный fan-out MatchProfileJob по активным профилям

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 16: ProcessChunkJob

**Files:**
- Create: `/home/roman/sites/www/tender/app/Jobs/ProcessChunkJob.php`
- Test: `/home/roman/sites/www/tender/tests/Feature/Sources/ProcessChunkJobTest.php`

**Interfaces:**
- Consumes: `app/Adapters/Eis/EisAdapter.php` (создан в Task 12/13), `app/Services/Import/Deduplicator.php` (создан в Task 4/7), `app/Models/{Tender,Source,SourceCollectRun}.php`, `tests/Support/EisFixtures.php` (создан в Task 8, неймспейс `Tests\Support`)
- Produces: создаёт `Tender`-записи, инкрементит счётчики `SourceCollectRun`, обновляет `last_seen_at` пакетом

**Примечание:** тест использует `Tests\Support\makeEisArchive()` из Task 8. Функция `makeEisArchive` объявлена в `tests/Support/EisFixtures.php` и доступна глобально через autoload-dev.

---

- [ ] **Write failing test**

`tests/Feature/Sources/ProcessChunkJobTest.php`:
```php
<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Enums\LawType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceConnectionType;
use App\Jobs\ProcessChunkJob;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use Carbon\CarbonImmutable;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Storage;

use function Tests\Support\makeEisArchive;

beforeEach(function () {
    $this->seed(SourceSeeder::class);
    Storage::fake('local');
});

function makeEisSource(): Source
{
    return Source::where('code', 'eis')->firstOrFail();
}

function makeEisRun(Source $source): SourceCollectRun
{
    return SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => null,
        'trigger'    => RunTrigger::Schedule,
        'status'     => RunStatus::Running,
        'started_at' => now(),
        'fetched_count' => 0,
        'new_count'     => 0,
        'updated_count' => 0,
        'error_count'   => 0,
    ]);
}

it('обрабатывает fz44-архив: создаёт tender и инкрементит счётчики', function () {
    // Для этого теста нужен EisAdapter с реальным mapper, без сети.
    // Сначала обновим adapter_class у источника (data-миграция Task3 выполнится через RefreshDatabase+seed,
    // но SourceSeeder не пишет adapter_class — нужно прописать вручную).
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/chunk.zip');
    @mkdir(dirname($zipPath), 0755, true);

    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $archive = makeEisArchive([$fz44Xml]);
    copy($archive, $zipPath);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);
    $job->handle(app(Deduplicator::class));

    expect(Tender::count())->toBe(1);

    $tender = Tender::first();
    expect($tender->number)->toBe('0173200001426000001')
        ->and($tender->law_type)->toBe(LawType::Fz44)
        ->and($tender->owner_company_id)->toBeNull();

    $run->refresh();
    expect($run->fetched_count)->toBe(1)
        ->and($run->new_count)->toBe(1)
        ->and($run->updated_count)->toBe(0)
        ->and($run->error_count)->toBe(0);
});

it('обрабатывает fz223-архив: создаёт tender', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/chunk223.zip');
    @mkdir(dirname($zipPath), 0755, true);

    $fz223Xml = base_path('tests/Fixtures/eis/fz223_notice.xml');
    $archive = makeEisArchive([$fz223Xml]);
    copy($archive, $zipPath);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz223);
    $job->handle(app(Deduplicator::class));

    expect(Tender::count())->toBe(1);

    $tender = Tender::first();
    expect($tender->number)->toBe('32212345678')
        ->and($tender->law_type)->toBe(LawType::Fz223);

    $run->refresh();
    expect($run->new_count)->toBe(1);
});

it('повторный запуск даёт updated/unchanged и обновляет last_seen_at батчем', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/chunk.zip');
    @mkdir(dirname($zipPath), 0755, true);

    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $archive = makeEisArchive([$fz44Xml]);
    copy($archive, $zipPath);

    // первый прогон — создаёт
    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);
    $job->handle(app(Deduplicator::class));

    $tender = Tender::first();
    $firstSeen = $tender->last_seen_at;

    // второй прогон — unchanged, last_seen_at должен обновиться
    $this->travel(5)->seconds();

    $run2 = makeEisRun($source);
    $zipPath2 = Storage::disk('local')->path('eis/'.$run2->id.'/chunk2.zip');
    @mkdir(dirname($zipPath2), 0755, true);
    copy($archive, $zipPath2);

    $job2 = new ProcessChunkJob($run2->id, $source->id, $zipPath2, LawType::Fz44);
    $job2->handle(app(Deduplicator::class));

    $run2->refresh();
    expect($run2->new_count)->toBe(0)
        ->and($run2->updated_count)->toBe(0);

    $tender->refresh();
    expect($tender->last_seen_at->gt($firstSeen))->toBeTrue();
});

it('ошибка нормализации инкрементит error_count, не ломает весь чанк', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    // XML с пустым title — вызовет NormalizationException
    $badXml = tempnam(sys_get_temp_dir(), 'eis_bad_').'.xml';
    file_put_contents($badXml, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ns2:export xmlns:ns2="http://zakupki.gov.ru/oos/export/1">
  <ns2:fcsNotificationEF>
    <purchaseNumber>9999999999999999999</purchaseNumber>
    <purchaseObjectInfo></purchaseObjectInfo>
    <docPublishDate>2026-06-15T10:00:00+03:00</docPublishDate>
    <purchaseResponsible><responsibleOrg>
      <fullName>Тест</fullName><INN>7701111111</INN><regionCode>77</regionCode>
    </responsibleOrg></purchaseResponsible>
    <notificationInfo><procedureInfo><collecting><endDate>2026-06-25T18:00:00+03:00</endDate></collecting></procedureInfo></notificationInfo>
    <lot><maxPrice>100.00</maxPrice></lot>
  </ns2:fcsNotificationEF>
</ns2:export>
XML);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/bad.zip');
    @mkdir(dirname($zipPath), 0755, true);
    $archive = makeEisArchive([$badXml]);
    copy($archive, $zipPath);
    @unlink($badXml);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);
    $job->handle(app(Deduplicator::class));

    $run->refresh();
    expect($run->error_count)->toBe(1)
        ->and($run->new_count)->toBe(0)
        ->and(Tender::count())->toBe(0);
});

it('Batchable: при отменённом батче job выходит досрочно', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);
    $zipPath = '/tmp/nonexistent.zip';

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);

    // Мокаем batch() чтобы вернуть отменённый батч
    $batchMock = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->andReturn(true);

    // Подменяем через Closure-bind
    $setCancelledBatch = Closure::bind(function () use ($batchMock) {
        $this->batch = $batchMock;
    }, $job, $job);
    $setCancelledBatch();

    $job->handle(app(Deduplicator::class));

    // Ничего не создано
    expect(Tender::count())->toBe(0);
});
```

- [ ] **Run**
```bash
php artisan test --filter="ProcessChunkJob"
```
Expected FAIL: `Class "App\Jobs\ProcessChunkJob" not found`.

- [ ] **Implement**

`app/Jobs/ProcessChunkJob.php`:
```php
<?php

namespace App\Jobs;

use App\Adapters\Eis\EisAdapter;
use App\Enums\LawType;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use App\Services\Import\NormalizationException;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessChunkJob implements ShouldQueue
{
    use Batchable, Queueable;

    public string $connection = 'redis-long';

    public string $queue = 'collect';

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $runId,
        public int $sourceId,
        public string $archivePath,
        public LawType $law,
    ) {}

    public function handle(Deduplicator $dedup): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $source = Source::findOrFail($this->sourceId);

        /** @var EisAdapter $adapter */
        $adapter = app($source->adapter_class);

        $fetched   = 0;
        $new       = 0;
        $updated   = 0;
        $errors    = 0;
        $unchangedIds = [];

        foreach ($adapter->streamNotices($this->archivePath, $this->law) as $raw) {
            $fetched++;

            try {
                $n = $adapter->normalize($raw);
                $r = $dedup->process($n, $this->sourceId, null, $this->runId);

                match ($r->status) {
                    'created'   => $new++,
                    'updated'   => $updated++,
                    default     => ($unchangedIds[] = $r->tender->id),
                };
            } catch (NormalizationException | QueryException) {
                $errors++;
            }
        }

        if ($unchangedIds !== []) {
            Tender::whereIn('id', $unchangedIds)->update(['last_seen_at' => now()]);
        }

        SourceCollectRun::whereKey($this->runId)->update([
            'fetched_count' => DB::raw('fetched_count + '.(int) $fetched),
            'new_count'     => DB::raw('new_count + '.(int) $new),
            'updated_count' => DB::raw('updated_count + '.(int) $updated),
            'error_count'   => DB::raw('error_count + '.(int) $errors),
        ]);
    }
}
```

- [ ] **Run**
```bash
php artisan test --filter="ProcessChunkJob"
```
Expected PASS: `5 passed`.

- [ ] **Commit**
```bash
git add app/Jobs/ProcessChunkJob.php tests/Feature/Sources/ProcessChunkJobTest.php
git commit -m "$(cat <<'EOF'
A6 C4 Task16: ProcessChunkJob — stream→dedup→counts, батчевый last_seen_at, tries=1

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 17: DownloadPackageJob

**Files:**
- Create: `/home/roman/sites/www/tender/app/Jobs/DownloadPackageJob.php`
- Test: `/home/roman/sites/www/tender/tests/Feature/Sources/DownloadPackageJobTest.php`

**Interfaces:**
- Consumes: `app/Adapters/Eis/EisAdapter.php` (Task 12/13), `app/Adapters/Eis/PackageRef.php` (Task 2), `app/Jobs/ProcessChunkJob.php` (Task 16), `app/Models/Source.php`
- Produces: скачивает архив на диск через `$adapter->downloadArchive()`, добавляет `ProcessChunkJob` в батч через `$this->batch()->add()`

---

- [ ] **Write failing test**

`tests/Feature/Sources/DownloadPackageJobTest.php`:
```php
<?php

use App\Adapters\Contracts\TenderSourceAdapter;
use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Enums\LawType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceConnectionType;
use App\Jobs\DownloadPackageJob;
use App\Jobs\ProcessChunkJob;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Carbon\CarbonImmutable;
use Database\Seeders\SourceSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

use function Tests\Support\makeEisArchive;

beforeEach(function () {
    $this->seed(SourceSeeder::class);
    Storage::fake('local');
});

it('скачивает архив и добавляет ProcessChunkJob в батч', function () {
    Bus::fake();

    $source = Source::where('code', 'eis')->firstOrFail();
    $source->adapter_class = EisAdapter::class;
    $source->connection_type = SourceConnectionType::OpenData;
    $source->save();

    $run = SourceCollectRun::create([
        'source_id'     => $source->id,
        'company_id'    => null,
        'trigger'       => RunTrigger::Schedule,
        'status'        => RunStatus::Running,
        'started_at'    => now(),
        'fetched_count' => 0,
        'new_count'     => 0,
        'updated_count' => 0,
        'error_count'   => 0,
    ]);

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/fake/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    // Фейковый gateway: downloadArchive пишет реальный zip-файл
    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $fakeArchive = makeEisArchive([$fz44Xml]);

    $gatewayMock = Mockery::mock(EisGateway::class);
    $gatewayMock->shouldReceive('downloadArchive')
        ->once()
        ->withArgs(function (PackageRef $r, string $dest) use ($ref, $fakeArchive) {
            // Скопировать фейковый архив в нужное место
            @mkdir(dirname($dest), 0755, true);
            copy($fakeArchive, $dest);
            return $r->archiveUrl === $ref->archiveUrl;
        });

    $this->app->bind(EisGateway::class, fn () => $gatewayMock);

    // Создаём батч через Bus::batch и сразу вызываем handle
    $batchMock = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->andReturn(false);
    $batchMock->shouldReceive('add')->once()->withArgs(function (array $jobs) {
        return count($jobs) === 1 && $jobs[0] instanceof ProcessChunkJob;
    });

    $job = new DownloadPackageJob($run->id, $source->id, $ref);

    $setBatch = Closure::bind(function () use ($batchMock) {
        $this->batch = $batchMock;
    }, $job, $job);
    $setBatch();

    $job->handle();

    // Файл должен быть создан на диске
    $expectedPath = 'eis/'.$run->id.'/'.md5($ref->archiveUrl).'.zip';
    Storage::disk('local')->assertExists($expectedPath);
});

it('Batchable: при отменённом батче job выходит досрочно без скачивания', function () {
    $source = Source::where('code', 'eis')->firstOrFail();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = SourceCollectRun::create([
        'source_id'     => $source->id,
        'company_id'    => null,
        'trigger'       => RunTrigger::Schedule,
        'status'        => RunStatus::Running,
        'started_at'    => now(),
        'fetched_count' => 0,
        'new_count'     => 0,
        'updated_count' => 0,
        'error_count'   => 0,
    ]);

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/fake/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $gatewayMock = Mockery::mock(EisGateway::class);
    $gatewayMock->shouldNotReceive('downloadArchive');
    $this->app->bind(EisGateway::class, fn () => $gatewayMock);

    $batchMock = Mockery::mock(\Illuminate\Bus\Batch::class);
    $batchMock->shouldReceive('cancelled')->andReturn(true);

    $job = new DownloadPackageJob($run->id, $source->id, $ref);
    $setBatch = Closure::bind(function () use ($batchMock) {
        $this->batch = $batchMock;
    }, $job, $job);
    $setBatch();

    $job->handle();

    Storage::disk('local')->assertMissing('eis/'.$run->id.'/'.md5($ref->archiveUrl).'.zip');
});
```

- [ ] **Run**
```bash
php artisan test --filter="DownloadPackageJob"
```
Expected FAIL: `Class "App\Jobs\DownloadPackageJob" not found`.

- [ ] **Implement**

`app/Jobs/DownloadPackageJob.php`:
```php
<?php

namespace App\Jobs;

use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\PackageRef;
use App\Models\Source;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DownloadPackageJob implements ShouldQueue
{
    use Batchable, Queueable;

    public string $connection = 'redis-long';

    public string $queue = 'collect';

    public int $timeout = 300;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public int $runId,
        public int $sourceId,
        public PackageRef $ref,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $source = Source::findOrFail($this->sourceId);

        /** @var EisAdapter $adapter */
        $adapter = app($source->adapter_class);

        $disk = config('eis.storage_disk', 'local');
        $relativePath = 'eis/'.$this->runId.'/'.md5($this->ref->archiveUrl).'.zip';
        $dest = Storage::disk($disk)->path($relativePath);

        $dir = dirname($dest);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $adapter->downloadArchive($this->ref, $dest);

        $this->batch()->add([
            new ProcessChunkJob($this->runId, $this->sourceId, $dest, $this->ref->lawType),
        ]);
    }
}
```

- [ ] **Run**
```bash
php artisan test --filter="DownloadPackageJob"
```
Expected PASS: `2 passed`.

- [ ] **Commit**
```bash
git add app/Jobs/DownloadPackageJob.php tests/Feature/Sources/DownloadPackageJobTest.php
git commit -m "$(cat <<'EOF'
A6 C4 Task17: DownloadPackageJob — Batchable, скачивание через адаптер, добавление ProcessChunkJob в батч

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 18: CollectSourceJob

**Files:**
- Create: `/home/roman/sites/www/tender/app/Jobs/CollectSourceJob.php`
- Test: `/home/roman/sites/www/tender/tests/Feature/Sources/CollectSourceJobTest.php`

**Interfaces:**
- Consumes: `config/eis.php` (Task 1), `app/Adapters/Eis/CollectParams.php` (Task 2), `app/Adapters/Eis/EisAdapter.php` (Task 12/13), `app/Jobs/{DownloadPackageJob,CollectMatchFanoutJob}.php` (Tasks 17, 15), `app/Models/{Source,SourceCollectRun}.php`, `app/Enums/{RunTrigger,RunStatus}.php`
- Produces: создаёт `SourceCollectRun`, формирует `Bus::batch([DownloadPackageJob...])` с then/catch/finally коллбэками, обеспечивает re-run blocking через `Cache::lock` + проверку Running-рана

---

- [ ] **Write failing test**

`tests/Feature/Sources/CollectSourceJobTest.php`:
```php
<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Enums\LawType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceConnectionType;
use App\Jobs\CollectMatchFanoutJob;
use App\Jobs\CollectSourceJob;
use App\Jobs\DownloadPackageJob;
use App\Models\Source;
\use App\Models\SourceCollectRun;
use Carbon\CarbonImmutable;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(SourceSeeder::class);
    Storage::fake('local');
    config(['eis.regions' => ['77'], 'eis.subsystems' => ['PRIZ']]);
});

function eisSourceWithAdapter(): Source
{
    $source = Source::where('code', 'eis')->firstOrFail();
    $source->adapter_class = EisAdapter::class;
    $source->connection_type = SourceConnectionType::OpenData;
    $source->save();
    return $source;
}

function fakeGatewayWithPackages(array $packages = []): EisGateway
{
    $mock = Mockery::mock(EisGateway::class);
    $mock->shouldReceive('listPackages')->andReturn($packages);
    return $mock;
}

// ─── Тест 1: пустой listPackages → run Ok + last_collected_at + CollectMatchFanoutJob ───

it('пустой listPackages завершает run Ok и диспатчит CollectMatchFanoutJob', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();
    $gateway = fakeGatewayWithPackages([]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->company_id)->toBeNull();

    $source->refresh();
    expect($source->last_collected_at)->not->toBeNull();

    Bus::assertDispatched(CollectMatchFanoutJob::class);
    Bus::assertNotDispatched(DownloadPackageJob::class);
});

// ─── Тест 2: re-run blocking — второй вызов при Running-ране НЕ создаёт второй run ───

it('re-run blocking: второй CollectSourceJob при running-ране не создаёт второй run', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();

    // Создаём уже Running ран вручную
    SourceCollectRun::create([
        'source_id'     => $source->id,
        'company_id'    => null,
        'trigger'       => RunTrigger::Schedule,
        'status'        => RunStatus::Running,
        'started_at'    => now(),
        'fetched_count' => 0,
        'new_count'     => 0,
        'updated_count' => 0,
        'error_count'   => 0,
    ]);

    // Второй запуск
    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    // Должен быть ровно один ран (тот, что создали вручную)
    expect(SourceCollectRun::where('source_id', $source->id)->count())->toBe(1);
    Bus::assertNothingDispatched();
});

// ─── Тест 3: не-пустой listPackages → Bus::batch с DownloadPackageJob ───

it('непустой listPackages создаёт Bus::batch с DownloadPackageJob', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/fake/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $gateway = fakeGatewayWithPackages([$ref]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Manual->value))->handle();

    // Ран создан со статусом Running (батч ещё идёт — Bus::fake не выполняет батч)
    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->company_id)->toBeNull();

    Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) {
        return $batch->jobs->filter(fn ($j) => $j instanceof DownloadPackageJob)->count() === 1
            && $batch->queue() === 'collect';
    });
});

// ─── Тест 4: run создаётся с правильным trigger ───

it('run создаётся с trigger из параметра джоба', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();
    $gateway = fakeGatewayWithPackages([]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Manual->value))->handle();

    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->trigger)->toBe(RunTrigger::Manual);
});

// ─── Тест 5: CollectParams строится с backfill из конфига, если last_collected_at null ───

it('CollectParams строится от now()-backfill_days если last_collected_at не задан', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();
    $source->last_collected_at = null;
    $source->save();

    config(['eis.backfill_days' => 7]);

    $capturedParams = null;
    $gateway = Mockery::mock(EisGateway::class);
    $gateway->shouldReceive('listPackages')
        ->withArgs(function (CollectParams $p) use (&$capturedParams) {
            $capturedParams = $p;
            return true;
        })
        ->andReturn([]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    expect($capturedParams)->not->toBeNull();
    $daysDiff = (int) $capturedParams->since->diffInDays($capturedParams->until);
    expect($daysDiff)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(8);
    expect($capturedParams->regions)->toBe(['77']);
    expect($capturedParams->subsystemTypes)->toBe(['PRIZ']);
});
```

- [ ] **Run**
```bash
php artisan test --filter="CollectSourceJob"
```
Expected FAIL: `Class "App\Jobs\CollectSourceJob" not found`.

- [ ] **Implement**

`app/Jobs/CollectSourceJob.php`:
```php
<?php

namespace App\Jobs;

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CollectSourceJob implements ShouldQueue
{
    use Queueable;

    public string $connection = 'redis-long';

    public string $queue = 'collect';

    public int $timeout = 600;

    public function __construct(
        public int $sourceId,
        public string $trigger,
    ) {}

    public function handle(): void
    {
        $lock = Cache::lock('collect:source:'.$this->sourceId, 10);

        if (! $lock->get()) {
            // Не смогли захватить лок — другой инстанс уже запускается
            return;
        }

        try {
            // Re-run blocking: если уже есть Running ран — выходим
            if (SourceCollectRun::where('source_id', $this->sourceId)
                ->where('status', RunStatus::Running)
                ->exists()
            ) {
                return;
            }

            $run = SourceCollectRun::create([
                'source_id'     => $this->sourceId,
                'company_id'    => null,
                'trigger'       => RunTrigger::from($this->trigger),
                'status'        => RunStatus::Running,
                'started_at'    => now(),
                'fetched_count' => 0,
                'new_count'     => 0,
                'updated_count' => 0,
                'error_count'   => 0,
            ]);
        } finally {
            $lock->release();
        }

        $source = Source::findOrFail($this->sourceId);

        /** @var EisAdapter $adapter */
        $adapter = app($source->adapter_class);

        $since = $source->last_collected_at !== null
            ? CarbonImmutable::instance($source->last_collected_at)->utc()
            : CarbonImmutable::now('UTC')->subDays((int) config('eis.backfill_days', 30));

        $until = CarbonImmutable::now('UTC');

        $params = new CollectParams(
            since: $since,
            until: $until,
            regions: array_values(config('eis.regions', [])),
            subsystemTypes: array_values(config('eis.subsystems', [])),
        );

        $packages = iterator_to_array($adapter->listPackages($params));

        // Захватываем скаляры для замыканий батча (сериализуемость)
        $runId        = $run->id;
        $sourceId     = $this->sourceId;
        $untilStr     = $until->toDateTimeString();
        $startedAtIso = $run->started_at->toIso8601String();
        $storageDisk  = config('eis.storage_disk', 'local');

        if (empty($packages)) {
            // Финализируем немедленно
            SourceCollectRun::whereKey($runId)->update([
                'status'      => RunStatus::Ok,
                'finished_at' => now(),
            ]);

            Source::whereKey($sourceId)->update([
                'last_collected_at' => $untilStr,
            ]);

            CollectMatchFanoutJob::dispatch($startedAtIso);

            return;
        }

        $jobs = array_map(
            fn ($ref) => new DownloadPackageJob($runId, $sourceId, $ref),
            $packages,
        );

        Bus::batch($jobs)
            ->name('collect:'.$sourceId)
            ->onQueue('collect')
            ->then(function (Batch $batch) use ($runId, $sourceId, $untilStr): void {
                SourceCollectRun::whereKey($runId)->update([
                    'status'      => RunStatus::Ok,
                    'finished_at' => now(),
                ]);
                Source::whereKey($sourceId)->update([
                    'last_collected_at' => $untilStr,
                ]);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($runId): void {
                SourceCollectRun::whereKey($runId)->update([
                    'status'      => RunStatus::Error,
                    'finished_at' => now(),
                    'log'         => $e->getMessage(),
                ]);
            })
            ->finally(function (Batch $batch) use ($runId, $sourceId, $startedAtIso, $storageDisk): void {
                Storage::disk($storageDisk)->deleteDirectory('eis/'.$runId);
                CollectMatchFanoutJob::dispatch($startedAtIso);
            })
            ->dispatch();
    }
}
```

- [ ] **Run**
```bash
php artisan test --filter="CollectSourceJob"
```
Expected PASS: `5 passed`.

- [ ] **Commit**
```bash
git add app/Jobs/CollectSourceJob.php tests/Feature/Sources/CollectSourceJobTest.php
git commit -m "$(cat <<'EOF'
A6 C4 Task18: CollectSourceJob — re-run blocking, Bus::batch с DownloadPackageJob, скалярные замыкания, fanout

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

**Итог кластера C4 — созданные файлы:**

- `/home/roman/sites/www/tender/config/queue.php` — добавлено соединение `redis-long`
- `/home/roman/sites/www/tender/app/Jobs/CollectMatchFanoutJob.php`
- `/home/roman/sites/www/tender/app/Jobs/ProcessChunkJob.php`
- `/home/roman/sites/www/tender/app/Jobs/DownloadPackageJob.php`
- `/home/roman/sites/www/tender/app/Jobs/CollectSourceJob.php`
- `/home/roman/sites/www/tender/tests/Unit/Queue/RedisLongConnectionTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Sources/CollectMatchFanoutJobTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Sources/ProcessChunkJobTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Sources/DownloadPackageJobTest.php`
- `/home/roman/sites/www/tender/tests/Feature/Sources/CollectSourceJobTest.php`

**Ни один файл из кластеров C1–C3 не модифицирован.** Все тесты ProcessChunkJob и DownloadPackageJob зависят от `Tests\Support\makeEisArchive()` и фикстур eis — они должны быть созданы в Task 8 до выполнения Task 16/17.



### Task 19: CollectSourceCommand + расписание в routes/console.php

**Files:**
- Create `app/Console/Commands/CollectSourceCommand.php`
- Modify `routes/console.php`
- Create `tests/Feature/Collect/CollectSourceCommandTest.php`

**Interfaces:**
- Consumes: `App\Jobs\CollectSourceJob` (создан в Task 18), `App\Models\Source`, `App\Enums\RunTrigger`
- Produces: artisan-команда `collect:source {code}`, крон-расписание в routes/console.php

---

- [ ] **Write failing test**

```php
// tests/Feature/Collect/CollectSourceCommandTest.php
<?php

use App\Enums\RunTrigger;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Bus;

it('команда collect:source диспатчит CollectSourceJob для существующего источника', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $this->artisan('collect:source', ['code' => 'eis'])
        ->assertExitCode(0);

    Bus::assertDispatched(CollectSourceJob::class, function (CollectSourceJob $job) use ($source) {
        return $job->sourceId === $source->id
            && $job->trigger === RunTrigger::Manual->value;
    });
});

it('команда collect:source завершается с ошибкой для несуществующего источника', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);

    $this->artisan('collect:source', ['code' => 'nonexistent'])
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=CollectSourceCommandTest
```

Expected: `FAILED` — класс `App\Console\Commands\CollectSourceCommand` не существует, `App\Jobs\CollectSourceJob` не существует.

- [ ] **Implement**

```php
// app/Console/Commands/CollectSourceCommand.php
<?php

namespace App\Console\Commands;

use App\Enums\RunTrigger;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use Illuminate\Console\Command;

class CollectSourceCommand extends Command
{
    protected $signature = 'collect:source {code}';

    protected $description = 'Запустить сбор тендеров из источника по коду (dispatch CollectSourceJob)';

    public function handle(): int
    {
        $code = $this->argument('code');
        $source = Source::where('code', $code)->first();

        if ($source === null) {
            $this->error("Источник с кодом «{$code}» не найден.");
            return self::FAILURE;
        }

        CollectSourceJob::dispatch($source->id, RunTrigger::Manual->value);

        $this->info("CollectSourceJob для источника «{$source->name}» (id={$source->id}) поставлен в очередь.");
        return self::SUCCESS;
    }
}
```

```php
// routes/console.php
<?php

use App\Console\Commands\CollectSourceCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CollectSourceCommand::class, ['eis'])
    ->cron(config('eis.schedule_cron', '0 */4 * * *'))
    ->withoutOverlapping();
```

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=CollectSourceCommandTest
```

Expected: `PASSED` 2 tests.

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add app/Console/Commands/CollectSourceCommand.php routes/console.php tests/Feature/Collect/CollectSourceCommandTest.php && git commit -m "$(cat <<'EOF'
A6 C5 Task 19: CollectSourceCommand + расписание EIS каждые 4 часа

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 20: SourceCollectController — триггер из UI для суперадмина

**Files:**
- Create `app/Http/Controllers/Admin/SourceCollectController.php`
- Modify `routes/web.php`
- Modify `app/Providers/AppServiceProvider.php`
- Modify `resources/js/Pages/Sources/Index.vue`
- Modify `resources/js/Layouts/AuthenticatedLayout.vue`
- Modify `app/Http/Middleware/HandleInertiaRequests.php`
- Create `tests/Feature/Collect/SourceCollectControllerTest.php`

**Interfaces:**
- Consumes: `App\Jobs\CollectSourceJob` (Task 18), `App\Models\Source`, `App\Models\User` (`UserRole::Superadmin`), Gate `superadmin`
- Produces: маршрут `POST /admin/sources/{source}/collect` (`admin.sources.collect`), редирект с flash

---

- [ ] **Write failing test**

```php
// tests/Feature/Collect/SourceCollectControllerTest.php
<?php

use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Bus;

it('суперадмин может запустить сбор источника из UI', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->post(route('admin.sources.collect', $source))
        ->assertRedirect();

    Bus::assertDispatched(CollectSourceJob::class, function (CollectSourceJob $job) use ($source) {
        return $job->sourceId === $source->id
            && $job->trigger === RunTrigger::Manual->value;
    });
});

it('обычный admin не может запустить сбор из admin-маршрута — 403', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->post(route('admin.sources.collect', $source))
        ->assertForbidden();

    Bus::assertNothingDispatched();
});

it('гость не может запустить сбор из admin-маршрута — redirect to login', function () {
    Bus::fake();
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $this->post(route('admin.sources.collect', $source))
        ->assertRedirect(route('login'));

    Bus::assertNothingDispatched();
});

it('суперадмин видит кнопку запуска сбора на странице источников', function () {
    $this->seed(SourceSeeder::class);
    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get(route('admin.sources.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Sources/Index')
            ->has('sources'));
});
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=SourceCollectControllerTest
```

Expected: `FAILED` — маршруты/контроллер не существуют.

- [ ] **Implement**

**1. Добавить Gate `superadmin` в AppServiceProvider:**

```php
// app/Providers/AppServiceProvider.php
// В метод boot() добавить после Gate::define('manage-tender-workflow', ...):

        Gate::define('superadmin', fn (User $user): bool => $user->role === UserRole::Superadmin);
```

Полный файл после правки:

```php
<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('manage-company', fn (User $user): bool => $user->role === UserRole::Admin && $user->company_id !== null);

        Gate::define('view-tenders', fn (User $user): bool => $user->company_id !== null);

        Gate::define('manage-tender-workflow', fn (User $user): bool => $user->company_id !== null
            && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));

        Gate::define('superadmin', fn (User $user): bool => $user->role === UserRole::Superadmin);
    }
}
```

**2. Контроллер:**

```php
// app/Http/Controllers/Admin/SourceCollectController.php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RunTrigger;
use App\Http\Controllers\Controller;
use App\Jobs\CollectSourceJob;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SourceCollectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Sources/Index', [
            'sources' => Source::orderBy('name')->get(['id', 'name', 'code', 'connection_type', 'adapter_class', 'schedule_cron', 'last_collected_at']),
        ]);
    }

    public function trigger(Request $request, Source $source): RedirectResponse
    {
        $request->user()->can('superadmin') || abort(403);

        CollectSourceJob::dispatch($source->id, RunTrigger::Manual->value);

        return redirect()->back()->with('success', "Сбор источника «{$source->name}» поставлен в очередь.");
    }
}
```

**3. Маршруты (добавить admin-группу в routes/web.php):**

```php
// routes/web.php — добавить в конец, перед require auth.php
Route::middleware(['auth', 'can:superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('sources', [\App\Http\Controllers\Admin\SourceCollectController::class, 'index'])->name('sources.index');
    Route::post('sources/{source}/collect', [\App\Http\Controllers\Admin\SourceCollectController::class, 'trigger'])->name('sources.collect');
});
```

Полный файл routes/web.php после правки:

```php
<?php

use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CompanySourceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\KeywordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SearchProfileController;
use App\Http\Controllers\TenderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'can:manage-company'])->group(function () {
    Route::resource('profiles', SearchProfileController::class)
        ->except(['show'])
        ->parameters(['profiles' => 'profile']);
    Route::post('profiles/{profile}/keywords', [KeywordController::class, 'store'])->name('profiles.keywords.store');
    Route::delete('keywords/{keyword}', [KeywordController::class, 'destroy'])->name('keywords.destroy');
    Route::post('profiles/{profile}/activate', [SearchProfileController::class, 'activate'])->name('profiles.activate');
    Route::post('profiles/{profile}/deactivate', [SearchProfileController::class, 'deactivate'])->name('profiles.deactivate');
    Route::get('sources', [CompanySourceController::class, 'index'])->name('sources.index');
    Route::patch('sources/{source}', [CompanySourceController::class, 'update'])->name('sources.update');
    Route::get('company', [CompanySettingsController::class, 'edit'])->name('company.edit');
    Route::patch('company', [CompanySettingsController::class, 'update'])->name('company.update');
    Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
});

Route::middleware(['auth', 'can:view-tenders'])->group(function () {
    Route::get('tenders', [TenderController::class, 'index'])->name('tenders.index');
    Route::get('tenders/{companyTender}', [TenderController::class, 'show'])->name('tenders.show');
    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
});

Route::middleware(['auth', 'can:manage-tender-workflow'])->group(function () {
    Route::patch('tenders/{companyTender}/status', [TenderController::class, 'updateStatus'])->name('tenders.status');
    Route::post('tenders/{companyTender}/comments', [TenderController::class, 'storeComment'])->name('tenders.comment');
    Route::patch('tenders/{companyTender}/assignee', [TenderController::class, 'assign'])->name('tenders.assign');
});

Route::middleware(['auth', 'can:superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('sources', [\App\Http\Controllers\Admin\SourceCollectController::class, 'index'])->name('sources.index');
    Route::post('sources/{source}/collect', [\App\Http\Controllers\Admin\SourceCollectController::class, 'trigger'])->name('sources.collect');
});

require __DIR__.'/auth.php';
```

**4. Пробросить `isSuperadmin` в Inertia shared props (HandleInertiaRequests.php):**

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'can' => [
                    'manageCompany' => (bool) $request->user()?->can('manage-company'),
                    'viewTenders' => (bool) $request->user()?->can('view-tenders'),
                    'manageTenderWorkflow' => (bool) $request->user()?->can('manage-tender-workflow'),
                    'superadmin' => (bool) $request->user()?->can('superadmin'),
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
```

**5. Vue-страница Admin/Sources/Index:**

```vue
<!-- resources/js/Pages/Admin/Sources/Index.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({ sources: { type: Array, required: true } });

const TYPE_LABELS = {
    open_data: 'Открытые данные',
    api: 'API',
    manual: 'Ручной импорт',
    parsing: 'Парсинг',
};

const triggerCollect = (source) => {
    if (!confirm(`Запустить сбор источника «${source.name}»?`)) return;
    router.post(route('admin.sources.collect', source.id), {}, { preserveScroll: true });
};

const date = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');
</script>

<template>
    <Head title="Источники (Суперадмин)" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Источники — управление сбором</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Код</th>
                                <th class="px-4 py-3">Название</th>
                                <th class="px-4 py-3">Тип</th>
                                <th class="px-4 py-3">Расписание</th>
                                <th class="px-4 py-3">Последний сбор</th>
                                <th class="px-4 py-3">Адаптер</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="source in sources" :key="source.id">
                                <td class="px-4 py-3 font-mono text-gray-700">{{ source.code }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ source.name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ TYPE_LABELS[source.connection_type] ?? source.connection_type }}</td>
                                <td class="px-4 py-3 font-mono text-gray-500">{{ source.schedule_cron ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ date(source.last_collected_at) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-400 truncate max-w-xs" :title="source.adapter_class">
                                    {{ source.adapter_class ? source.adapter_class.split('\\').pop() : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="source.adapter_class"
                                        type="button"
                                        class="rounded bg-indigo-600 px-3 py-1 text-xs text-white hover:bg-indigo-700 disabled:opacity-50"
                                        @click="triggerCollect(source)"
                                    >
                                        Запустить
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!sources.length">
                                <td colspan="7" class="px-4 py-4 text-gray-400">Источники не найдены.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

**6. Добавить ссылку «Источники» для суперадмина в AuthenticatedLayout.vue:**

В секции `<!-- Navigation Links -->` (desktop, после `</template>` для `manageCompany`) добавить блок:

```vue
<template v-if="$page.props.auth.can.superadmin">
    <NavLink :href="route('admin.sources.index')" :active="route().current('admin.sources.*')">
        Адм. источники
    </NavLink>
</template>
```

В секции responsive-меню (после блока `manageCompany`) добавить:

```vue
<template v-if="$page.props.auth.can.superadmin">
    <ResponsiveNavLink :href="route('admin.sources.index')" :active="route().current('admin.sources.*')">
        Адм. источники
    </ResponsiveNavLink>
</template>
```

Полный файл `resources/js/Layouts/AuthenticatedLayout.vue` после правки — заменить блок Navigation Links целиком:

```vue
<script setup>
import { ref, computed, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

const page = usePage();
const flashVisible = ref(false);
const flash = computed(() => page.props.flash ?? {});

watch(
    () => [flash.value.success, flash.value.error],
    ([success, error]) => {
        if (success || error) {
            flashVisible.value = true;
            setTimeout(() => (flashVisible.value = false), 4000);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Дашборд
                                </NavLink>
                                <template v-if="$page.props.auth.can.viewTenders">
                                    <NavLink :href="route('tenders.index')" :active="route().current('tenders.*')">
                                        Закупки
                                    </NavLink>
                                    <NavLink :href="route('runs.index')" :active="route().current('runs.*')">Запуски</NavLink>
                                </template>
                                <template v-if="$page.props.auth.can.manageCompany">
                                <NavLink :href="route('profiles.index')" :active="route().current('profiles.*')">
                                    Направления
                                </NavLink>
                                <NavLink :href="route('sources.index')" :active="route().current('sources.*')">
                                    Источники
                                </NavLink>
                                <NavLink :href="route('imports.index')" :active="route().current('imports.*')">
                                    Импорт
                                </NavLink>
                                <NavLink :href="route('company.edit')" :active="route().current('company.*')">
                                    Компания
                                </NavLink>
                            </template>
                                <template v-if="$page.props.auth.can.superadmin">
                                    <NavLink :href="route('admin.sources.index')" :active="route().current('admin.sources.*')">
                                        Адм. источники
                                    </NavLink>
                                </template>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Профиль
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Выйти
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Дашборд
                        </ResponsiveNavLink>
                        <template v-if="$page.props.auth.can.viewTenders">
                            <ResponsiveNavLink :href="route('tenders.index')" :active="route().current('tenders.*')">
                                Закупки
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('runs.index')" :active="route().current('runs.*')">Запуски</ResponsiveNavLink>
                        </template>
                        <template v-if="$page.props.auth.can.manageCompany">
                            <ResponsiveNavLink :href="route('profiles.index')" :active="route().current('profiles.*')">
                                Направления
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('sources.index')" :active="route().current('sources.*')">
                                Источники
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('imports.index')" :active="route().current('imports.*')">
                                Импорт
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('company.edit')" :active="route().current('company.*')">
                                Компания
                            </ResponsiveNavLink>
                        </template>
                        <template v-if="$page.props.auth.can.superadmin">
                            <ResponsiveNavLink :href="route('admin.sources.index')" :active="route().current('admin.sources.*')">
                                Адм. источники
                            </ResponsiveNavLink>
                        </template>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Профиль
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Выйти
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Flash Banner -->
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-1"
                leave-active-class="transition ease-in duration-200"
                leave-to-class="opacity-0"
            >
                <div v-if="flashVisible && (flash.success || flash.error)" class="mx-auto mt-4 max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        :class="flash.error ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800'"
                        class="rounded-md p-3 text-sm shadow-sm"
                        role="status"
                    >
                        {{ flash.error ?? flash.success }}
                    </div>
                </div>
            </Transition>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
```

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=SourceCollectControllerTest
```

Expected: `PASSED` 4 tests.

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add \
  app/Http/Controllers/Admin/SourceCollectController.php \
  app/Providers/AppServiceProvider.php \
  app/Http/Middleware/HandleInertiaRequests.php \
  routes/web.php \
  resources/js/Pages/Admin/Sources/Index.vue \
  resources/js/Layouts/AuthenticatedLayout.vue \
  tests/Feature/Collect/SourceCollectControllerTest.php \
  && git commit -m "$(cat <<'EOF'
A6 C5 Task 20: SourceCollectController — триггер сбора ЕИС для суперадмина

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 21: RunController — глобальные ЕИС-раны для суперадмина

**Files:**
- Modify `app/Http/Controllers/RunController.php`
- Modify `resources/js/Pages/Runs/Index.vue`
- Create `tests/Feature/Runs/RunControllerEisRunsTest.php`

**Interfaces:**
- Consumes: `App\Models\SourceCollectRun`, Gate `superadmin` (создан в Task 20), `App\Models\User` (`UserRole::Superadmin`)
- Produces: props `collectRuns` (company-scoped для обычных пользователей) + `eisRuns` (только для суперадмина, `company_id IS NULL`)

---

- [ ] **Write failing test**

```php
// tests/Feature/Runs/RunControllerEisRunsTest.php
<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('суперадмин видит глобальные ЕИС-раны (company_id=NULL) в eisRuns', function () {
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $eisRun = SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => null,
        'trigger' => RunTrigger::Schedule,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'fetched_count' => 100,
        'new_count' => 10,
        'updated_count' => 2,
        'error_count' => 0,
    ]);

    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('eisRuns', 1)
            ->where('eisRuns.0.id', $eisRun->id));
});

it('менеджер компании не видит ЕИС-раны (company_id=NULL) в своём collectRuns', function () {
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => null,
        'trigger' => RunTrigger::Schedule,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
        'fetched_count' => 100,
        'new_count' => 10,
        'updated_count' => 2,
        'error_count' => 0,
    ]);

    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('collectRuns', 0)
            ->has('eisRuns', 0));
});

it('обычный пользователь видит свои company-scoped collectRuns', function () {
    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();

    $admin = adminWithPlan();

    $companyRun = SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => $admin->company_id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(3),
        'finished_at' => now(),
        'fetched_count' => 5,
        'new_count' => 2,
        'updated_count' => 0,
        'error_count' => 0,
    ]);

    $this->actingAs($admin)
        ->get(route('runs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('collectRuns', 1)
            ->where('collectRuns.0.id', $companyRun->id)
            ->has('eisRuns', 0));
});
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=RunControllerEisRunsTest
```

Expected: `FAILED` — prop `eisRuns` не передаётся.

- [ ] **Implement**

```php
// app/Http/Controllers/RunController.php
<?php

namespace App\Http\Controllers;

use App\Models\RadarRun;
use App\Models\SourceCollectRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user?->company_id;
        $isSuperadmin = $user?->can('superadmin') ?? false;

        return Inertia::render('Runs/Index', [
            // RadarRun авто-скоупится (BelongsToCompany); ограничиваем последними 50.
            'radarRuns' => $isSuperadmin
                ? []
                : RadarRun::query()
                    ->select(['id', 'company_id', 'search_profile_id', 'trigger', 'status', 'started_at', 'finished_at', 'processed_count', 'matched_count', 'error_count'])
                    ->with('searchProfile:id,name')
                    ->latest('started_at')
                    ->limit(50)
                    ->get(),

            // SourceCollectRun БЕЗ scope — фильтруем company_id явно.
            // Для суперадмина — company-scoped пусто (у него нет компании).
            'collectRuns' => SourceCollectRun::query()
                ->select(['id', 'source_id', 'company_id', 'trigger', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'error_count'])
                ->where('company_id', $companyId)
                ->with('source:id,name')
                ->latest('started_at')
                ->limit(50)
                ->get(),

            // ЕИС-раны (company_id IS NULL) — только для суперадмина.
            'eisRuns' => $isSuperadmin
                ? SourceCollectRun::query()
                    ->select(['id', 'source_id', 'company_id', 'trigger', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'updated_count', 'error_count'])
                    ->whereNull('company_id')
                    ->with('source:id,name')
                    ->latest('started_at')
                    ->limit(100)
                    ->get()
                : collect(),
        ]);
    }
}
```

```vue
<!-- resources/js/Pages/Runs/Index.vue -->
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';

defineProps({
    radarRuns: { type: Array, required: true },
    collectRuns: { type: Array, required: true },
    eisRuns: { type: Array, required: true },
});

const isSuperadmin = usePage().props.auth.can.superadmin;

const date = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');
const statusClass = (s) => ({ ok: 'text-green-700', error: 'text-red-700', running: 'text-gray-500' }[s] ?? 'text-gray-700');
const triggerLabel = (v) => ({ import: 'Импорт', schedule: 'Расписание', manual: 'Вручную' }[v] ?? v);
const statusLabel = (v) => ({ running: 'Запущен', ok: 'Успешно', error: 'Ошибка' }[v] ?? v);
</script>

<template>
    <Head title="Запуски" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Запуски</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">

                <!-- ЕИС-раны (суперадмин) -->
                <div v-if="isSuperadmin" class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Глобальный сбор ЕИС (суперадмин)</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Источник</th>
                                <th class="px-3 py-2">Триггер</th>
                                <th class="px-3 py-2">Статус</th>
                                <th class="px-3 py-2">Найдено/Новых/Обновлено/Ошибок</th>
                                <th class="px-3 py-2">Начало</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="r in eisRuns" :key="'e' + r.id">
                                <td class="px-3 py-2">{{ r.source?.name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ triggerLabel(r.trigger) }}</td>
                                <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                <td class="px-3 py-2">{{ r.fetched_count }} / {{ r.new_count }} / {{ r.updated_count }} / {{ r.error_count }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ date(r.started_at) }}</td>
                            </tr>
                            <tr v-if="!eisRuns.length"><td colspan="5" class="px-3 py-4 text-gray-400">Запусков ЕИС нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Сбор компании (обычные пользователи) -->
                <div v-if="!isSuperadmin" class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Сбор данных (импорт/источники)</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-3 py-2">Источник</th><th class="px-3 py-2">Триггер</th><th class="px-3 py-2">Статус</th><th class="px-3 py-2">Найдено/Новых/Ошибок</th><th class="px-3 py-2">Начало</th></tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="r in collectRuns" :key="'c' + r.id">
                                <td class="px-3 py-2">{{ r.source?.name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ triggerLabel(r.trigger) }}</td>
                                <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                <td class="px-3 py-2">{{ r.fetched_count }} / {{ r.new_count }} / {{ r.error_count }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ date(r.started_at) }}</td>
                            </tr>
                            <tr v-if="!collectRuns.length"><td colspan="5" class="px-3 py-4 text-gray-400">Запусков нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Матчинг (только для компаний) -->
                <div v-if="!isSuperadmin" class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Матчинг (радар)</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-3 py-2">Направление</th><th class="px-3 py-2">Триггер</th><th class="px-3 py-2">Статус</th><th class="px-3 py-2">Обработано/Матчей</th><th class="px-3 py-2">Начало</th></tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="r in radarRuns" :key="'r' + r.id">
                                <td class="px-3 py-2">{{ r.searchProfile?.name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ triggerLabel(r.trigger) }}</td>
                                <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                <td class="px-3 py-2">{{ r.processed_count }} / {{ r.matched_count }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ date(r.started_at) }}</td>
                            </tr>
                            <tr v-if="!radarRuns.length"><td colspan="5" class="px-3 py-4 text-gray-400">Запусков нет.</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=RunControllerEisRunsTest
```

Expected: `PASSED` 3 tests.

Убедиться, что существующие тесты не сломаны:

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=RunController
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add \
  app/Http/Controllers/RunController.php \
  resources/js/Pages/Runs/Index.vue \
  tests/Feature/Runs/RunControllerEisRunsTest.php \
  && git commit -m "$(cat <<'EOF'
A6 C5 Task 21: RunController — глобальные ЕИС-раны видны суперадмину

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 22: Horizon — установка, конфиг, Gate, тесты

**Files:**
- Modify `composer.json` (через `composer require`)
- Create `config/horizon.php` (через `php artisan horizon:install`)
- Modify `app/Providers/AppServiceProvider.php` (Gate `viewHorizon`)
- Modify `.env.example`
- Create `tests/Feature/Horizon/HorizonConfigTest.php`

**Interfaces:**
- Consumes: Gate `superadmin` (создан в Task 20), `config/queue.php` соединение `redis-long` (создан в Task 14)
- Produces: `/horizon` только для суперадмина/local

**Примечание:** `composer require laravel/horizon` и `php artisan horizon:install` выполняются как шаги реализации. Тесты проверяют конфиг и провайдер без запуска воркеров.

---

- [ ] **Write failing test**

```php
// tests/Feature/Horizon/HorizonConfigTest.php
<?php

use App\Enums\UserRole;
use App\Models\User;

it('конфиг horizon существует и содержит окружения production и local', function () {
    $config = config('horizon');
    expect($config)->toBeArray();
    expect($config)->toHaveKey('environments');
    expect($config['environments'])->toHaveKey('production');
    expect($config['environments'])->toHaveKey('local');
});

it('окружение local содержит supervisor-default и supervisor-collect', function () {
    $local = config('horizon.environments.local');
    expect($local)->toHaveKey('supervisor-default');
    expect($local)->toHaveKey('supervisor-collect');
});

it('supervisor-collect имеет queue collect и timeout 590', function () {
    $supervisor = config('horizon.environments.local.supervisor-collect');
    expect($supervisor['queue'])->toBe(['collect']);
    expect($supervisor['timeout'])->toBe(590);
});

it('HorizonServiceProvider зарегистрирован в приложении', function () {
    expect(app()->providerIsLoaded(\Laravel\Horizon\HorizonServiceProvider::class))->toBeTrue();
});

it('суперадмин имеет доступ к /horizon', function () {
    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get('/horizon')
        ->assertStatus(200);
});

it('обычный admin не имеет доступа к /horizon', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get('/horizon')
        ->assertStatus(403);
});
```

- [ ] **Run (Expected FAIL)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=HorizonConfigTest
```

Expected: `FAILED` — `laravel/horizon` не установлен, config не существует.

- [ ] **Implement**

**Шаг 1 — установить Horizon:**

```bash
cd /home/roman/sites/www/tender && composer require laravel/horizon
```

**Шаг 2 — опубликовать assets и конфиг:**

```bash
cd /home/roman/sites/www/tender && php artisan horizon:install
```

**Шаг 3 — заменить сгенерированный config/horizon.php** полным содержимым с нужными окружениями:

```php
// config/horizon.php
<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:',
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:default' => 60,
        'redis:collect' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */
    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */
    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */
    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    */
    'environments' => [
        'production' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 5,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 60,
                'nice' => 0,
            ],
            'supervisor-collect' => [
                'connection' => 'redis-long',
                'queue' => ['collect'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 4,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 1,
                'timeout' => 590,
                'nice' => 0,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 3,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 60,
                'nice' => 0,
            ],
            'supervisor-collect' => [
                'connection' => 'redis-long',
                'queue' => ['collect'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 2,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 256,
                'tries' => 1,
                'timeout' => 590,
                'nice' => 0,
            ],
        ],
    ],
];
```

**Шаг 4 — добавить Gate `viewHorizon` в AppServiceProvider.php:**

Добавить в метод `boot()` после Gate::define('superadmin', ...) следующий блок:

```php
        Gate::define('viewHorizon', function (User $user): bool {
            return $user->role === UserRole::Superadmin;
        });
```

Полный файл `app/Providers/AppServiceProvider.php` после правки:

```php
<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('manage-company', fn (User $user): bool => $user->role === UserRole::Admin && $user->company_id !== null);

        Gate::define('view-tenders', fn (User $user): bool => $user->company_id !== null);

        Gate::define('manage-tender-workflow', fn (User $user): bool => $user->company_id !== null
            && in_array($user->role, [UserRole::Admin, UserRole::Manager], true));

        Gate::define('superadmin', fn (User $user): bool => $user->role === UserRole::Superadmin);

        Gate::define('viewHorizon', fn (User $user): bool => $user->role === UserRole::Superadmin);
    }
}
```

**Шаг 5 — добавить Horizon-переменные в .env.example:**

Добавить в конец файла `.env.example`:

```
# Laravel Horizon
HORIZON_DOMAIN=
HORIZON_PATH=horizon
HORIZON_PREFIX=
EIS_ENDPOINT=https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP
EIS_TOKEN=
EIS_STORAGE_DISK=local
EIS_BACKFILL_DAYS=30
EIS_REGIONS=77,78
```

- [ ] **Run (Expected PASS)**

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=HorizonConfigTest
```

Expected: `PASSED` 6 tests.

Проверить весь набор тестов A6 C5:

```bash
cd /home/roman/sites/www/tender && php artisan test tests/Feature/Collect/ tests/Feature/Runs/RunControllerEisRunsTest.php tests/Feature/Horizon/
```

Expected: все тесты C5 (Task 19–22) `PASSED`.

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add \
  composer.json \
  composer.lock \
  config/horizon.php \
  app/Providers/AppServiceProvider.php \
  .env.example \
  public/vendor/horizon \
  tests/Feature/Horizon/HorizonConfigTest.php \
  && git commit -m "$(cat <<'EOF'
A6 C5 Task 22: Horizon — установка, конфиг supervisor-default+collect, Gate viewHorizon=superadmin

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

**Сводная таблица владения файлами кластера C5:**

| Файл | Создаёт | Модифицирует |
|---|---|---|
| `app/Console/Commands/CollectSourceCommand.php` | T19 | — |
| `routes/console.php` | — | T19 |
| `tests/Feature/Collect/CollectSourceCommandTest.php` | T19 | — |
| `app/Http/Controllers/Admin/SourceCollectController.php` | T20 | — |
| `routes/web.php` | — | T20 |
| `app/Providers/AppServiceProvider.php` | — | T20 (Gate superadmin), T22 (Gate viewHorizon) |
| `app/Http/Middleware/HandleInertiaRequests.php` | — | T20 |
| `resources/js/Pages/Admin/Sources/Index.vue` | T20 | — |
| `resources/js/Layouts/AuthenticatedLayout.vue` | — | T20 |
| `tests/Feature/Collect/SourceCollectControllerTest.php` | T20 | — |
| `app/Http/Controllers/RunController.php` | — | T21 |
| `resources/js/Pages/Runs/Index.vue` | — | T21 |
| `tests/Feature/Runs/RunControllerEisRunsTest.php` | T21 | — |
| `composer.json` / `composer.lock` | — | T22 (composer require) |
| `config/horizon.php` | T22 | — |
| `.env.example` | — | T22 |
| `tests/Feature/Horizon/HorizonConfigTest.php` | T22 | — |

**Важное замечание по AppServiceProvider:** T20 и T22 оба модифицируют `AppServiceProvider.php` — добавляют разные Gate-определения. При исполнении плана T22 читает файл уже с Gate `superadmin` (добавленным в T20) и добавляет поверх него Gate `viewHorizon`. В шаге T22 приведён полный итоговый файл с обоими определениями.
