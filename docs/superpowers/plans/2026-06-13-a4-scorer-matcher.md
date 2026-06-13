# A4: Scorer + Matcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Импортированные/собранные закупки матчатся с активными профилями компании, получают детерминированный объяснимый балл, уровень, риск-флаги и рекомендацию; результат складывается в `tender_matches`, а лучший балл проецируется в `company_tenders`.

**Architecture:** Конвейер из чистых юнит-тестируемых компонентов поверх PostgreSQL-предфильтра. `CandidateQuery` (FTS GIN + trigram ILIKE, §7.6) сужает глобальную таблицу `tenders` до кандидатов профиля. `KeywordMatchResolver` определяет, в каком поле (название/описание) сработал каждый ключ: для не-exact ключей — морфологически через `plainto_tsquery('russian')` (стеммер), для exact-фраз и аббревиатур — нормализованным substring-поиском в PHP. `HardFilter` (§7.1) отсекает непрошедших. Чистый `Scorer` (§7.2) считает балл по разрешённым попаданиям и полям закупки/профиля; `ScoringWeights` мержит частичный override профиля поверх дефолтов `config/scoring.php`. `MatchProfileService` оркеструет прогон в рамках `radar_runs`, персистит матчи и атомарно (advisory-lock на пару company:tender) пересчитывает `best_*` в `company_tenders`. `MatchProfileJob` — очередная обёртка; ручной импорт диспатчит её для активных профилей компании.

**Tech Stack:** Laravel 13, PostgreSQL 16 (tsvector `russian` + `pg_trgm`, generated-колонка `search_vector` уже есть в схеме A1), Eloquent, Pest 4, PHPStan L6, Pint, ext-bcmath.

---

## Контекст: что уже есть (этапы A1–A3)

- **Схема целиком накатана в A1.** Все таблицы из §5 существуют, включая `tenders` (с `search_vector` generated + GIN + GIN trigram(title)), `company_tenders`, `tender_matches`, `radar_runs`, `search_profiles`, `keywords`, `sources`, `company_sources`. A4 **новых таблиц не создаёт**, только опциональный composite-индекс по EXPLAIN (Задача 10).
- **Enums** (готовы): `App\Enums\KeywordType` (`Main`/`Exact`/`Broad`/`Negative`/`HighPriority`/`LowPriority`, методы `isPositive()`, `positiveCases()`), `LawType` (`Fz44`/`Fz223`/`Commercial`/`Other`), `RelevanceLevel` (`High`/`Medium`/`Weak`/`Low`, статик `fromScore(int): self` — пороги 80/60/40, сравнение «≥»), `ActionRecommendation` (`HandOver`/`ReviewDocs`/`Monitor`/`Decline`), `TenderSourceStatus` (`Active`/`Completed`/`Cancelled`/`Unknown`), `RunTrigger` (`Manual`/`Schedule`/`Import`), `RunStatus` (`Running`/`Ok`/`Error`).
- **Модели и касты** (важные для матчера):
  - `Tender` — глобальная, **без** `BelongsToCompany`; `$guarded = ['id', 'search_vector']`; касты: `price` → `decimal:2` (**возвращает string!**), `law_type`/`source_status` → enum, `published_at`/`deadline_at`/`first_seen_at`/`last_seen_at` → `datetime` (Carbon), `okpd2`/`ktru`/`extra` → `array`.
  - `SearchProfile` — `BelongsToCompany`; касты: `law_types`/`regions`/`excluded_regions`/`okpd2_codes`/`scoring_weights` → `array`, `min_price`/`max_price` → `decimal:2` (**string!** — сравнения через `bccomp`), `notification_threshold`/`deadline_min_days` → `integer`, `exclude_on_negative_in_title` → `boolean`. Метод `keywords(): HasMany`, `canActivate(): bool`.
  - `Keyword` — `$guarded=['id']`; касты `type` → `KeywordType`, `origin` → `KeywordOrigin`; поля `value`, `search_profile_id`.
  - `CompanyTender` — `BelongsToCompany`; касты `best_score` → `integer`, `best_level` → `RelevanceLevel`, `first_matched_at` → `datetime`. **`matches()` бросает `RuntimeException` без TenantContext** — в джобах НЕ использовать, считать `best_*` явным запросом по `company_id`.
  - `TenderMatch` — `BelongsToCompany`; касты `score`→int, `level`→`RelevanceLevel`, `reason`/`matched_keywords`/`matched_negative_keywords`/`risk_flags`/`ai_assessment`→`array`, `action_recommendation`→`ActionRecommendation`. unique `(search_profile_id, tender_id)`.
  - `RadarRun` — `BelongsToCompany`; касты `trigger`→`RunTrigger`, `status`→`RunStatus`, `started_at`/`finished_at`→`datetime`. Колонки: `processed_count`, `matched_count`, `notified_count`, `error_count`, `log`. **Фабрики нет** — создавать через `::create([...])` с явным `company_id`.
  - `CompanySource` — `BelongsToCompany`; `is_active`→bool; `config`→`encrypted:array` (**никогда в WHERE**). «Включённый источник компании» = строка `company_sources` с `is_active = true`.
  - `Source` — `connection_type`→`SourceConnectionType`. Источник ручного импорта: `code = 'manual'`.
- **Tenancy** (`app/Tenancy/`):
  - `TenantContext` — scoped singleton, `set(?int)`, `id(): ?int`.
  - `BelongsToCompany` — глобальный scope `company` активен **только когда `TenantContext::id() !== null`** (иначе no-op → запрос невидоизменён). `creating`-хук проставляет `company_id` из контекста, **только если он null** в модели. Статик `withoutTenancy(): Builder` — снимает read-scope (creating-автозаполнение продолжает работать). **Инвариант: в джобах (контекст не установлен) `company_id` при insert/upsert передавать явно; запросы фильтровать явным `where('company_id', ...)`.**
- **Импорт** (`app/Services/Import/`):
  - `Canonicalizer::text(?string): string` — `lower + trim + NBSP→пробел + схлопывание пробелов + ё→е`. **Переиспользуем** для нормализованного поиска фраз/аббревиатур.
  - `Deduplicator` — паттерн advisory-lock: `DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$key])` внутри `DB::transaction`. **Повторяем этот паттерн** для сериализации пересчёта `best_*`.
  - `ManualImportService::import()` — в конце успешного прогона стоит комментарий-плейсхолдер `// A4: здесь диспатчится MatchProfileJob ...` (строка ~61). Задача 9 заменяет его на диспатч.
- **Тесты** (Pest): `tests/Pest.php` — Feature использует `RefreshDatabase`, `Unit/Import` — `TestCase` без БД. Стиль: `it('...', function () {...})`, хелперы-функции вверху файла, `expect()->toBe()->and()`, фабрики, `app(Service::class)`. Фичевые тесты ставят тенант через `app(TenantContext::class)->set($id)`.
- **Запуск:** PG-тест-БД должна быть поднята: `docker compose -f docker-compose.dev.yml up -d --wait`. Тесты: `./vendor/bin/pest <path> --filter="..."`. Статика: `./vendor/bin/phpstan analyse`. Стиль: `./vendor/bin/pint`. Полный прогон: `composer test`.

## Файловая структура (создаётся в A4)

| Файл | Ответственность |
|------|-----------------|
| `config/scoring.php` | Дефолтные веса §7.3 (включая `deadline_far_days`/`deadline_soon_days`) |
| `app/Scoring/ScoringWeights.php` | VO: partial-merge override профиля поверх дефолтов; типизированный `get(string): int` |
| `app/Scoring/KeywordHit.php` | VO: `value`, `type: KeywordType`, `field: 'title'|'description'|null`; `matched()` |
| `app/Scoring/ScoreResult.php` | DTO результата скоринга (score/level/recommendation/reason/keywords/flags/excluded) |
| `app/Scoring/HardFilter.php` | §7.1 жёсткие фильтры (чистый, `passes()`) |
| `app/Scoring/Scorer.php` | §7.2 начисление баллов (чистый, `score()`) |
| `app/Scoring/CandidateQuery.php` | §7.6 SQL-предфильтр кандидатов (FTS + trigram + owner/source-scope) |
| `app/Scoring/KeywordMatchResolver.php` | Полевое попадание ключей: SQL-морфология + PHP exact/аббревиатуры |
| `app/Scoring/MatchProfileService.php` | Оркестрация прогона + персист + пересчёт `best_*` |
| `app/Jobs/MatchProfileJob.php` | Очередная обёртка над сервисом |
| `tests/Unit/Scoring/ScoringWeightsTest.php` | Юнит: merge весов |
| `tests/Unit/Scoring/ScorerTest.php` | Юнит: таблица кейсов §7.2, NULL, clamp, границы уровней |
| `tests/Unit/Scoring/HardFilterTest.php` | Юнит: §7.1 |
| `tests/Feature/Scoring/CandidateQueryTest.php` | Фича: FTS/trigram/owner/source-scope/since |
| `tests/Feature/Scoring/KeywordMatchResolverTest.php` | Фича: морфология/exact/аббревиатуры/негативы |
| `tests/Feature/Scoring/MatchProfileServiceTest.php` | Фича: матч→персист, `best_*` по нескольким профилям, исключение, счётчики |
| `tests/Feature/Scoring/MatchProfileJobTest.php` | Фича: гард удалённого/неактивного профиля |
| `tests/Feature/Scoring/ImportAutoMatchTest.php` | Фича: импорт→диспатч джоб (только активные профили) + сквозной матч |
| `tests/Feature/Scoring/GoldenSetTest.php` | Регрессия качества на эталонном наборе |
| `tests/Fixtures/golden/*.json` | Эталонный набор закупок и ожиданий |
| модификация `tests/Pest.php` | регистрация `Unit/Scoring` под `TestCase` (без БД) |
| модификация `app/Services/Import/ManualImportService.php` | диспатч `MatchProfileJob` |
| (опц.) `database/migrations/..._add_match_candidate_indexes.php` | composite-индекс по EXPLAIN (Задача 10) |

## Зафиксированные проектные решения (обоснование)

1. **Чистый `Scorer` ← разрешённые попадания.** Scorer не ходит в БД и не знает про морфологию: он принимает `list<KeywordHit>` (где сработал ключ) + закупку + профиль + веса. Это даёт точную «таблицу кейсов» из §13 без БД и развязывает скоринг от способа поиска. Морфология/FTS живут в `KeywordMatchResolver`.
2. **Морфология — для не-exact, substring — для exact/аббревиатур.** `plainto_tsquery('russian')` стеммит и матчит склонения, но для фразы ANDит леммы (не гарантирует смежность). Поэтому exact-фразы ищем нормализованным substring в PHP (смежно, как требует §7.6), остальные ключи — морфологически в SQL (FTS), плюс substring как подстраховка. Поле итогового попадания = title, если попадание в названии, иначе description, иначе null («максимум по полю», §7.2 правило 2). **Важно:** конфиг `russian` НЕ выкидывает короткие аббревиатуры — `КМД`/`НМЦК`/`КМ`/`ТЗ`/`СП` сохраняются как лексемы и ловятся FTS-веткой (в т.ч. в описании, weight B). ILIKE-ветка по ключам ≤4 симв. — безвредная избыточность, а не единственный способ их поймать.
3. **Предфильтр не должен терять кандидатов.** `CandidateQuery` ORит FTS-ветку (не-exact ключи: main/broad/high/low) и trigram/ILIKE-ветку по `title` (exact-фразы + ключи ≤4 симв.). FTS-ветка покрывает описание (search_vector включает description с weight B), поэтому **единственная реальная слепая зона** — exact-фраза, встречающаяся ТОЛЬКО в описании: exact-ключи в FTS-ветку не идут (нужна смежность), а ILIKE-ветка бьёт лишь по `title`. Это сознательный компромисс (без seq-scan по описанию), фиксируется отдельным тестом `CandidateQueryTest` и оговаривается. Не-exact ключи в описании (включая аббревиатуры) ловятся FTS-веткой — для них слепой зоны нет.
3a. **Нормализация ILIKE-ветки.** Resolver сравнивает канонизированные строки (`Canonicalizer::text`: lower + ё→е + схлопывание пробелов), а Postgres `ILIKE` регистронезависим, но НЕ приводит ё↔е. Чтобы предфильтр не терял кандидатов, которые resolver затем подтвердит, ILIKE-ветка ищет по нормализованному выражению `replace(lower(title), 'ё', 'е') LIKE replace(lower(:value),'ё','е')`. Остаточная микро-расхождение (схлопывание кратных пробелов внутри фразы) — редкий край, оговаривается. Опц. функциональный GIN trigram-индекс под это выражение — по EXPLAIN (Задача 10).
4. **`bccomp` для цен.** `price`/`min_price` после каста `decimal:2` — строки. Сравнения строго через `bccomp($a, $b, 2)`. Требуется `ext-bcmath` (Задача 3, шаг 0 проверяет).
5. **`best_*` — явным запросом, без `TenantContext`.** Джоб не ставит тенант-контекст (следуем задокументированному инварианту). Все запросы матчера — с явным `where('company_id', ...)` и `withoutTenancy()`; `CompanyTender::matches()` не используется.
6. **`first_matched_at` при upsert + advisory-lock.** `company_tenders.first_matched_at` — NOT NULL без default и без creating-хука. Создаём строку с явным `first_matched_at = now()`; при обновлении `best_*` его **не трогаем**. Пересчёт `best_*` (MAX по всем матчам пары) и upsert обёрнуты в `DB::transaction` + `pg_advisory_xact_lock(hashtext('ct:'.companyId.':'.tenderId))` — сериализует гонку параллельных джоб разных профилей одной компании по одной закупке (unique `(company_id, tender_id)` иначе словит 23505).
7. **manual-источник всегда включён для своих импортов.** К списку включённых источников компании всегда добавляем id источника `code='manual'` — компания импортирует собственные данные, и они обязаны матчиться, даже если manual не «включён» в `company_sources`.
8. **Матч создаётся только при ≥1 попадании НЕ-негативного ключа** (main/exact/broad/high_priority/low_priority). Внимание: это шире, чем `KeywordType::isPositive()` (= только main/exact/broad). Узкий «позитивный ключ» из спеки (§5: профиль нельзя активировать без ≥1 main/exact/broad) относится к АКТИВАЦИИ профиля, а не к созданию матча. Цель гейта — отсечь закупки без какой-либо текстовой релевантности, чтобы они не матчили профиль только по цене/региону. Закупка-кандидат без единого сработавшего не-негативного ключа не матчится.
9. **Исключение по негативу в названии** (`exclude_on_negative_in_title`): матч не создаётся; существующий матч этого профиля удаляется и `best_*` пересчитывается. `company_tenders` при этом **не удаляется** (сохраняем статус/историю пользователя) — лишь обнуляется `best_*`, если матчей не осталось.
10. **Кандидатный фильтр дедлайна (§4.2 шаг 2).** Помимо §7.1, спека §4.2 требует среди кандидатов условие `(deadline_at IS NULL OR deadline_at >= now() + deadline_min_days)`. Это НЕ §7.1 (там дедлайна нет) и НЕ §7.2-скоринг — отдельный кандидатный фильтр в `CandidateQuery`. При `deadline_min_days = 0` (дефолт) он отсекает уже просроченные закупки (`deadline_at < now()`); закупки с близким, но будущим дедлайном проходят и получают штраф `deadline_soon` в скоринге. `deadline_min_days` берётся из профиля (cast integer). **Тонкость для тестов:** этот фильтр выполняется в SQL и использует серверный `now()` (НЕ `Carbon::setTestNow`), тогда как дедлайн-скоринг в `Scorer` — на PHP `now()` (учитывает `setTestNow`). В тестах задавать дедлайны с дневной гранулярностью (offset ≥ 1 день) и фиксировать `setTestNow` на тот же календарный день, что и реальные часы прогона, — тогда обе проверки согласованы.
11. **Инкрементальность матчинга — отложена.** §4.2/§81 описывает матчинг по `tenders.updated_at > отметки прошлого RadarRun`. В A4 параметр `sinceUpdatedAt` в сервисе предусмотрен, но импорт/джоб передают `null` (полный рескан кандидатов). Для A4 (импорт-триггер, ограниченный объём) это корректно (матчи перезаписываются, `first_matched_at` сохраняется); вывод `sinceUpdatedAt` из предыдущего прогона подключается планировщиком этапа B (item 11). Уведомления (`NotifyJob`, `notified_count`, §4.2 шаг 4) — вне A4 (Telegram = этап A item 8 / A7).

---

## Задача 1: Дефолтные веса и ScoringWeights

**Files:**
- Create: `config/scoring.php`
- Create: `app/Scoring/ScoringWeights.php`
- Test: `tests/Unit/Scoring/ScoringWeightsTest.php`
- Modify: `tests/Pest.php` (регистрация `Unit/Scoring`)

- [ ] **Step 1: Конфиг весов**

Create `config/scoring.php`:

```php
<?php

return [
    // Дефолтные веса §7.3. Частичный override — search_profiles.scoring_weights (мержится поверх).
    // deadline_far_days/deadline_soon_days — пороги в днях (не баллы), тоже переопределяемы.
    'weights' => [
        'exact_in_title' => 30,
        'exact_in_description' => 15,
        'keyword_in_title' => 20,
        'keyword_in_description' => 15,
        'high_priority_bonus' => 10,
        'low_priority_score' => 2,
        'negative_keyword' => -20,
        'price_above_min' => 20,
        'price_below_min' => -10,
        'priority_region' => 10,
        'deadline_far_bonus' => 10,
        'deadline_soon_penalty' => -20,
        'deadline_far_days' => 5,
        'deadline_soon_days' => 2,
        'okpd_match' => 5,
        'source_closed' => -30,
    ],
];
```

- [ ] **Step 2: Регистрация Unit/Scoring в Pest**

Modify `tests/Pest.php` — добавить после блока `Unit/Import` (строка ~27):

```php
pest()->extend(TestCase::class)
    ->in('Unit/Scoring');
```

(Без `RefreshDatabase`: юнит-тесты скоринга не ходят в БД. **Важно:** `factory()->make()` без явного FK резолвит вложенную фабрику (`SearchProfileFactory` → `Company::factory()`, `TenderFactory` → `Source::factory()`) через `create()` — это INSERT в БД. Поэтому в хелперах юнит-тестов ВСЕГДА передаём скалярные `company_id`/`source_id` явно — тогда вложенные фабрики не вызываются и обращения к БД нет.)

- [ ] **Step 3: Написать падающий тест ScoringWeights**

Create `tests/Unit/Scoring/ScoringWeightsTest.php`:

```php
<?php

use App\Models\SearchProfile;
use App\Scoring\ScoringWeights;

it('возвращает дефолты, когда override профиля пуст', function () {
    $profile = SearchProfile::factory()->make(['company_id' => 1, 'scoring_weights' => null]);

    $weights = ScoringWeights::for($profile);

    expect($weights->get('exact_in_title'))->toBe(30)
        ->and($weights->get('keyword_in_description'))->toBe(15)
        ->and($weights->get('deadline_far_days'))->toBe(5);
});

it('частично мержит override поверх дефолтов', function () {
    $profile = SearchProfile::factory()->make([
        'company_id' => 1,
        'scoring_weights' => ['exact_in_title' => 50, 'deadline_soon_days' => 3],
    ]);

    $weights = ScoringWeights::for($profile);

    expect($weights->get('exact_in_title'))->toBe(50)         // переопределён
        ->and($weights->get('deadline_soon_days'))->toBe(3)   // переопределён
        ->and($weights->get('keyword_in_title'))->toBe(20);   // дефолт сохранён
});

it('игнорирует неизвестные ключи и не-числа в override', function () {
    $profile = SearchProfile::factory()->make([
        'company_id' => 1,
        'scoring_weights' => ['unknown_key' => 99, 'exact_in_title' => 'abc'],
    ]);

    $weights = ScoringWeights::for($profile);

    expect($weights->get('exact_in_title'))->toBe(30)
        ->and(fn () => $weights->get('unknown_key'))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 4: Запустить — упадёт (нет класса)**

Run: `./vendor/bin/pest tests/Unit/Scoring/ScoringWeightsTest.php`
Expected: FAIL — `Class "App\Scoring\ScoringWeights" not found`.

- [ ] **Step 5: Реализовать ScoringWeights**

Create `app/Scoring/ScoringWeights.php`:

```php
<?php

namespace App\Scoring;

use App\Models\SearchProfile;
use InvalidArgumentException;

final readonly class ScoringWeights
{
    /** @param array<string, int> $values */
    private function __construct(private array $values) {}

    public static function for(SearchProfile $profile): self
    {
        /** @var array<string, int> $defaults */
        $defaults = config('scoring.weights');
        /** @var array<string, mixed> $override */
        $override = $profile->scoring_weights ?? [];

        $merged = $defaults;
        foreach ($defaults as $key => $_) {
            if (array_key_exists($key, $override) && is_numeric($override[$key])) {
                $merged[$key] = (int) $override[$key];
            }
        }

        return new self($merged);
    }

    public function get(string $key): int
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Неизвестный вес скоринга: {$key}");
        }

        return $this->values[$key];
    }
}
```

- [ ] **Step 6: Запустить — зелёный**

Run: `./vendor/bin/pest tests/Unit/Scoring/ScoringWeightsTest.php`
Expected: PASS (3 теста).

- [ ] **Step 7: Pint + PHPStan**

Run: `./vendor/bin/pint app/Scoring config/scoring.php tests/Unit/Scoring && ./vendor/bin/phpstan analyse app/Scoring`
Expected: no style diff, `[OK] No errors`.

- [ ] **Step 8: Commit**

```bash
git add config/scoring.php app/Scoring/ScoringWeights.php tests/Unit/Scoring/ScoringWeightsTest.php tests/Pest.php
git commit -m "A4: config/scoring.php + ScoringWeights (partial-override весов)"
```

---

## Задача 2: VO попадания ключа и DTO результата

**Files:**
- Create: `app/Scoring/KeywordHit.php`
- Create: `app/Scoring/ScoreResult.php`
- Test: `tests/Unit/Scoring/KeywordHitTest.php`

- [ ] **Step 1: Падающий тест KeywordHit**

Create `tests/Unit/Scoring/KeywordHitTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Scoring\KeywordHit;

it('matched() истинно при ненулевом поле', function () {
    $hit = new KeywordHit('краны', KeywordType::Main, 'title');

    expect($hit->matched())->toBeTrue()
        ->and($hit->field)->toBe('title')
        ->and($hit->type)->toBe(KeywordType::Main);
});

it('matched() ложно, когда ключ не найден', function () {
    $hit = new KeywordHit('насосы', KeywordType::Broad, null);

    expect($hit->matched())->toBeFalse();
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Unit/Scoring/KeywordHitTest.php`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализовать KeywordHit**

Create `app/Scoring/KeywordHit.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\KeywordType;

final readonly class KeywordHit
{
    /** @param 'title'|'description'|null $field  поле, где сработал ключ (title приоритетнее) */
    public function __construct(
        public string $value,
        public KeywordType $type,
        public ?string $field,
    ) {}

    public function matched(): bool
    {
        return $this->field !== null;
    }
}
```

- [ ] **Step 4: Реализовать ScoreResult**

Create `app/Scoring/ScoreResult.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;

final readonly class ScoreResult
{
    /**
     * @param list<array{rule: string, points: int, detail: string}> $reason
     * @param list<array{value: string, type: string, field: string}> $matchedKeywords
     * @param list<array{value: string, type: string, field: string}> $matchedNegativeKeywords
     * @param list<string> $riskFlags
     */
    public function __construct(
        public int $score,
        public RelevanceLevel $level,
        public ActionRecommendation $recommendation,
        public array $reason,
        public array $matchedKeywords,
        public array $matchedNegativeKeywords,
        public array $riskFlags,
        public bool $excluded,
    ) {}
}
```

- [ ] **Step 5: Запустить — зелёный**

Run: `./vendor/bin/pest tests/Unit/Scoring/KeywordHitTest.php`
Expected: PASS (2 теста).

- [ ] **Step 6: Pint + PHPStan + Commit**

```bash
./vendor/bin/pint app/Scoring tests/Unit/Scoring && ./vendor/bin/phpstan analyse app/Scoring
git add app/Scoring/KeywordHit.php app/Scoring/ScoreResult.php tests/Unit/Scoring/KeywordHitTest.php
git commit -m "A4: KeywordHit VO + ScoreResult DTO"
```

---

## Задача 3: Scorer (§7.2)

**Files:**
- Create: `app/Scoring/Scorer.php`
- Test: `tests/Unit/Scoring/ScorerTest.php`
- Modify: `composer.json` (декларация `ext-bcmath`)

- [ ] **Step 0: Проверить bcmath**

Run: `php -m | grep -i bcmath`
Expected: `bcmath`. Если пусто — установить расширение (на dev-боксе PHP 8.3 ожидается включённым).

Modify `composer.json` — в блок `"require"` добавить строку (рядом с `"php"`):

```json
        "ext-bcmath": "*",
```

(Декларативно фиксирует зависимость; `composer update` не требуется — platform-требование проверяется при install.)

- [ ] **Step 1: Падающий тест Scorer — текстовые правила**

Create `tests/Unit/Scoring/ScorerTest.php`:

```php
<?php

use App\Enums\ActionRecommendation;
use App\Enums\KeywordType;
use App\Enums\LawType;
use App\Enums\RelevanceLevel;
use App\Enums\TenderSourceStatus;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Scoring\KeywordHit;
use App\Scoring\Scorer;
use App\Scoring\ScoringWeights;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Фиксируем «сейчас» для детерминизма дедлайн-правил.
    Carbon::setTestNow('2026-06-13 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Нейтральная по неключевым правилам пара: цена/мин не сравниваются (min null),
 * регион не приоритетный, дедлайн в «нейтральной» зоне (3 дня), статус active, окпд пуст.
 * Остаётся только вклад переданных попаданий ключей.
 */
function neutralTender(array $overrides = []): Tender
{
    return Tender::factory()->make(array_merge([
        'source_id' => 1,   // явный FK: иначе make() резолвит Source::factory() через create() (INSERT)
        'title' => 'Поставка кранов мостовых',
        'description' => 'Комплектная поставка оборудования',
        'price' => null,
        'region' => 'Тверь',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'deadline_at' => Carbon::parse('2026-06-16 12:00:00'), // +3 дня (нейтрально)
        'okpd2' => [],
        'ktru' => [],
        'owner_company_id' => null,
    ], $overrides));
}

function neutralProfile(array $overrides = []): SearchProfile
{
    return SearchProfile::factory()->make(array_merge([
        'company_id' => 1,  // явный FK: иначе make() резолвит Company::factory() через create() (INSERT)
        'min_price' => null,
        'max_price' => null,
        'regions' => [],
        'excluded_regions' => [],
        'okpd2_codes' => [],
        'law_types' => [],
        'exclude_on_negative_in_title' => false,
        'scoring_weights' => null,
    ], $overrides));
}

function score(Tender $tender, SearchProfile $profile, array $positive, array $negative = [])
{
    return app(Scorer::class)->score($tender, $profile, ScoringWeights::for($profile), $positive, $negative);
}

it('exact в названии → +30, правила 2-3 для неё не дублируют базу', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны мостовые', KeywordType::Exact, 'title'),
    ]);

    expect($r->score)->toBe(30)
        ->and($r->level)->toBe(RelevanceLevel::Low)
        ->and($r->recommendation)->toBe(ActionRecommendation::Decline)
        ->and($r->matchedKeywords)->toBe([
            ['value' => 'краны мостовые', 'type' => 'exact', 'field' => 'title'],
        ]);
});

it('exact только в описании → +15', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны мостовые', KeywordType::Exact, 'description'),
    ]);

    expect($r->score)->toBe(15);
});

it('main в названии → +20; в описании → +15 (максимум по полю)', function () {
    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ])->score)->toBe(20);

    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Broad, 'description'),
    ])->score)->toBe(15);
});

it('exact в названии + main в описании = 30 + 15 = 45', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны мостовые', KeywordType::Exact, 'title'),
        new KeywordHit('лебёдки', KeywordType::Main, 'description'),
    ]);

    expect($r->score)->toBe(45);
});

it('high_priority даёт +10 поверх базового правила', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),       // +20
        new KeywordHit('срочно', KeywordType::HighPriority, 'description'), // +10
    ]);

    expect($r->score)->toBe(30);
});

it('high_priority сам по себе не даёт +20/+15, только +10', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('срочно', KeywordType::HighPriority, 'title'),
    ]);

    expect($r->score)->toBe(10);
});

it('low_priority +2 только при отсутствии main/exact/broad', function () {
    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('доставка', KeywordType::LowPriority, 'title'),
    ])->score)->toBe(2);

    // есть main → low_priority не начисляется
    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
        new KeywordHit('доставка', KeywordType::LowPriority, 'title'),
    ])->score)->toBe(20);
});

it('негатив в любом поле → −20 однократно', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ], [
        new KeywordHit('бывшие в употреблении', KeywordType::Negative, 'description'),
    ]);

    expect($r->score)->toBe(0) // 20 - 20
        ->and($r->matchedNegativeKeywords)->toBe([
            ['value' => 'бывшие в употреблении', 'type' => 'negative', 'field' => 'description'],
        ]);
});

it('негатив в названии при exclude_on_negative_in_title → excluded, балл не считается', function () {
    $r = score(neutralTender(), neutralProfile(['exclude_on_negative_in_title' => true]), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ], [
        new KeywordHit('бу', KeywordType::Negative, 'title'),
    ]);

    expect($r->excluded)->toBeTrue();
});

it('негатив в описании при exclude_on_negative_in_title НЕ исключает (только −20)', function () {
    $r = score(neutralTender(), neutralProfile(['exclude_on_negative_in_title' => true]), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ], [
        new KeywordHit('бу', KeywordType::Negative, 'description'),
    ]);

    expect($r->excluded)->toBeFalse()->and($r->score)->toBe(0);
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Unit/Scoring/ScorerTest.php`
Expected: FAIL — `Class "App\Scoring\Scorer" not found`.

- [ ] **Step 3: Падающий тест Scorer — неключевые правила, флаги, clamp, уровни**

Append в `tests/Unit/Scoring/ScorerTest.php`:

```php
it('цена ≥ min → +20; цена < min → −10; флаг no_price при NULL', function () {
    $profile = neutralProfile(['min_price' => '1000000']);

    $above = score(neutralTender(['price' => '1500000']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($above->score)->toBe(40); // 20 + 20

    $below = score(neutralTender(['price' => '500000']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($below->score)->toBe(10); // 20 - 10

    $noPrice = score(neutralTender(['price' => null]), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($noPrice->score)->toBe(20)               // нейтрально
        ->and($noPrice->riskFlags)->toContain('no_price');
});

it('цена сравнивается через bccomp (без float-погрешности на копейках)', function () {
    $profile = neutralProfile(['min_price' => '1000000.10']);

    expect(score(neutralTender(['price' => '1000000.10']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ])->score)->toBe(40); // равно min → ≥ → +20

    expect(score(neutralTender(['price' => '1000000.09']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ])->score)->toBe(10); // на копейку меньше → −10
});

it('приоритетный регион → +10', function () {
    $r = score(neutralTender(['region' => 'Москва']), neutralProfile(['regions' => ['Москва']]), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);

    expect($r->score)->toBe(30);
});

it('дедлайн > far_days → +10; < soon_days → −20 + флаг; NULL → флаг no_deadline', function () {
    $far = score(neutralTender(['deadline_at' => Carbon::parse('2026-06-25 12:00:00')]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($far->score)->toBe(30); // +10

    $soon = score(neutralTender(['deadline_at' => Carbon::parse('2026-06-14 06:00:00')]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($soon->score)->toBe(0)                       // 20 - 20
        ->and($soon->riskFlags)->toContain('deadline_soon');

    $none = score(neutralTender(['deadline_at' => null]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($none->score)->toBe(20)
        ->and($none->riskFlags)->toContain('no_deadline');
});

it('окпд2 префиксное совпадение → +5; пустой список профиля — неактивно', function () {
    $withCode = score(
        neutralTender(['okpd2' => ['28.22.14.000']]),
        neutralProfile(['okpd2_codes' => ['28.22']]),
        [new KeywordHit('краны', KeywordType::Main, 'title')],
    );
    expect($withCode->score)->toBe(25); // +5

    $noProfileCodes = score(
        neutralTender(['okpd2' => ['28.22.14.000']]),
        neutralProfile(['okpd2_codes' => []]),
        [new KeywordHit('краны', KeywordType::Main, 'title')],
    );
    expect($noProfileCodes->score)->toBe(20);
});

it('source_status completed/cancelled → −30 + флаг source_closed; unknown как active', function () {
    $closed = score(neutralTender(['source_status' => TenderSourceStatus::Completed]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($closed->score)->toBe(0)                     // clamp: 20 - 30 = -10 → 0
        ->and($closed->riskFlags)->toContain('source_closed');

    $unknown = score(neutralTender(['source_status' => TenderSourceStatus::Unknown]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($unknown->score)->toBe(20);
});

it('итог обрезается в [0, 100]', function () {
    // exact_title 30 + main_title 20 уже невозможны вместе (exact гасит правило 2),
    // поэтому набираем потолок через override весов.
    $profile = neutralProfile([
        'min_price' => '1000000',
        'regions' => ['Москва'],
        'okpd2_codes' => ['28'],
        'scoring_weights' => ['exact_in_title' => 95],
    ]);
    $tender = neutralTender([
        'price' => '2000000',
        'region' => 'Москва',
        'okpd2' => ['28.22'],
        'deadline_at' => Carbon::parse('2026-07-01 12:00:00'),
    ]);

    $r = score($tender, $profile, [new KeywordHit('кран', KeywordType::Exact, 'title')]);
    // 95 + 20(price) + 10(region) + 5(okpd) + 10(deadline) = 140 → clamp 100
    expect($r->score)->toBe(100)->and($r->level)->toBe(RelevanceLevel::High)
        ->and($r->recommendation)->toBe(ActionRecommendation::HandOver);
});

it('границы уровней «≥»: 80→high, 60→medium, 40→weak, 39→low', function () {
    foreach ([[80, RelevanceLevel::High], [60, RelevanceLevel::Medium], [40, RelevanceLevel::Weak], [39, RelevanceLevel::Low]] as [$target, $level]) {
        $profile = neutralProfile(['scoring_weights' => ['keyword_in_title' => $target]]);
        $r = score(neutralTender(), $profile, [new KeywordHit('краны', KeywordType::Main, 'title')]);
        expect($r->score)->toBe($target)->and($r->level)->toBe($level);
    }
});

it('reason перечисляет сработавшие правила с баллами', function () {
    $r = score(neutralTender(['price' => '1500000']), neutralProfile(['min_price' => '1000000']), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);

    $rules = array_column($r->reason, 'points', 'rule');
    expect($rules)->toHaveKey('keyword_in_title')
        ->and($rules['keyword_in_title'])->toBe(20)
        ->and($rules)->toHaveKey('price_above_min')
        ->and($rules['price_above_min'])->toBe(20);
});
```

- [ ] **Step 4: Реализовать Scorer**

Create `app/Scoring/Scorer.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\ActionRecommendation;
use App\Enums\KeywordType;
use App\Enums\RelevanceLevel;
use App\Enums\TenderSourceStatus;
use App\Models\SearchProfile;
use App\Models\Tender;

final class Scorer
{
    /**
     * Детерминированный скоринг пары (закупка × профиль) по §7.2.
     * Принимает уже разрешённые попадания ключей (где сработал каждый) — морфология/поиск вне Scorer.
     *
     * @param list<KeywordHit> $positiveHits  попадания положительных ключей (main/exact/broad/high/low)
     * @param list<KeywordHit> $negativeHits  попадания негативных ключей
     */
    public function score(
        Tender $tender,
        SearchProfile $profile,
        ScoringWeights $weights,
        array $positiveHits,
        array $negativeHits,
    ): ScoreResult {
        $matchedNegatives = array_values(array_filter($negativeHits, fn (KeywordHit $h) => $h->matched()));

        // Раннее исключение: негатив в названии при включённом флаге — матч не создаётся (§7.2 п.5).
        $negativeInTitle = $this->anyInField($matchedNegatives, 'title');
        if ($negativeInTitle && $profile->exclude_on_negative_in_title) {
            return new ScoreResult(
                score: 0,
                level: RelevanceLevel::Low,
                recommendation: ActionRecommendation::Decline,
                reason: [],
                matchedKeywords: [],
                matchedNegativeKeywords: $this->toPayload($matchedNegatives),
                riskFlags: [],
                excluded: true,
            );
        }

        $matchedPositives = array_values(array_filter($positiveHits, fn (KeywordHit $h) => $h->matched()));

        $score = 0;
        $reason = [];
        $riskFlags = [];

        $add = function (int $points, string $rule, string $detail) use (&$score, &$reason): void {
            $score += $points;
            $reason[] = ['rule' => $rule, 'points' => $points, 'detail' => $detail];
        };

        // --- Текстовые правила (§7.2 п.1-5) ---
        $exactHits = $this->byType($matchedPositives, KeywordType::Exact);
        $mainBroadHits = $this->byTypes($matchedPositives, [KeywordType::Main, KeywordType::Broad]);
        $highHits = $this->byType($matchedPositives, KeywordType::HighPriority);
        $lowHits = $this->byType($matchedPositives, KeywordType::LowPriority);

        // Правило 1: exact
        if ($this->anyInField($exactHits, 'title')) {
            $add($weights->get('exact_in_title'), 'exact_in_title', $this->firstValue($exactHits, 'title'));
        } elseif ($this->anyInField($exactHits, 'description')) {
            $add($weights->get('exact_in_description'), 'exact_in_description', $this->firstValue($exactHits, 'description'));
        }

        // Правило 2: main/broad (максимум по полю)
        if ($this->anyInField($mainBroadHits, 'title')) {
            $add($weights->get('keyword_in_title'), 'keyword_in_title', $this->firstValue($mainBroadHits, 'title'));
        } elseif ($this->anyInField($mainBroadHits, 'description')) {
            $add($weights->get('keyword_in_description'), 'keyword_in_description', $this->firstValue($mainBroadHits, 'description'));
        }

        // Правило 3: high_priority bonus (+10 за наличие, не даёт базу сам по себе)
        if ($highHits !== []) {
            $add($weights->get('high_priority_bonus'), 'high_priority_bonus', $highHits[0]->value);
        }

        // Правило 4: low_priority +2 только при отсутствии main/exact/broad
        if ($lowHits !== [] && $exactHits === [] && $mainBroadHits === []) {
            $add($weights->get('low_priority_score'), 'low_priority_score', $lowHits[0]->value);
        }

        // Правило 5: негатив −20 однократно (исключение уже отработано выше)
        if ($matchedNegatives !== []) {
            $add($weights->get('negative_keyword'), 'negative_keyword', $matchedNegatives[0]->value);
        }

        // --- Прочие правила (§7.2) ---
        // Цена
        $price = $tender->price;            // string|null (decimal:2)
        $minPrice = $profile->min_price;    // string|null (decimal:2)
        if ($price === null) {
            $riskFlags[] = 'no_price';
        }
        if ($price !== null && $minPrice !== null) {
            if (bccomp($price, $minPrice, 2) >= 0) {
                $add($weights->get('price_above_min'), 'price_above_min', "{$price} ≥ {$minPrice}");
            } else {
                $add($weights->get('price_below_min'), 'price_below_min', "{$price} < {$minPrice}");
            }
        }

        // Приоритетный регион
        $regions = $profile->regions ?? [];
        if ($tender->region !== null && in_array($tender->region, $regions, true)) {
            $add($weights->get('priority_region'), 'priority_region', $tender->region);
        }

        // Дедлайн
        $deadline = $tender->deadline_at;
        if ($deadline === null) {
            $riskFlags[] = 'no_deadline';
        } else {
            $now = now();
            if ($deadline->greaterThan($now->copy()->addDays($weights->get('deadline_far_days')))) {
                $add($weights->get('deadline_far_bonus'), 'deadline_far_bonus', $deadline->toDateString());
            } elseif ($deadline->lessThan($now->copy()->addDays($weights->get('deadline_soon_days')))) {
                $add($weights->get('deadline_soon_penalty'), 'deadline_soon_penalty', $deadline->toDateString());
                $riskFlags[] = 'deadline_soon';
            }
        }

        // ОКПД2/КТРУ префиксное совпадение
        $profileCodes = array_filter($profile->okpd2_codes ?? [], fn ($c) => (string) $c !== '');
        if ($profileCodes !== []) {
            $tenderCodes = array_merge($tender->okpd2 ?? [], $tender->ktru ?? []);
            foreach ($tenderCodes as $tc) {
                foreach ($profileCodes as $pc) {
                    if (str_starts_with((string) $tc, (string) $pc)) {
                        $add($weights->get('okpd_match'), 'okpd_match', (string) $tc);
                        break 2;
                    }
                }
            }
        }

        // Статус источника
        if (in_array($tender->source_status, [TenderSourceStatus::Completed, TenderSourceStatus::Cancelled], true)) {
            $add($weights->get('source_closed'), 'source_closed', $tender->source_status->value);
            $riskFlags[] = 'source_closed';
        }

        // --- Итог ---
        $score = max(0, min(100, $score));
        $level = RelevanceLevel::fromScore($score);

        return new ScoreResult(
            score: $score,
            level: $level,
            recommendation: $this->recommendation($level),
            reason: $reason,
            matchedKeywords: $this->toPayload($matchedPositives),
            matchedNegativeKeywords: $this->toPayload($matchedNegatives),
            riskFlags: $riskFlags,
            excluded: false,
        );
    }

    private function recommendation(RelevanceLevel $level): ActionRecommendation
    {
        return match ($level) {
            RelevanceLevel::High => ActionRecommendation::HandOver,
            RelevanceLevel::Medium => ActionRecommendation::ReviewDocs,
            RelevanceLevel::Weak => ActionRecommendation::Monitor,
            RelevanceLevel::Low => ActionRecommendation::Decline,
        };
    }

    /**
     * @param list<KeywordHit> $hits
     * @return list<KeywordHit>
     */
    private function byType(array $hits, KeywordType $type): array
    {
        return array_values(array_filter($hits, fn (KeywordHit $h) => $h->type === $type));
    }

    /**
     * @param list<KeywordHit> $hits
     * @param list<KeywordType> $types
     * @return list<KeywordHit>
     */
    private function byTypes(array $hits, array $types): array
    {
        return array_values(array_filter($hits, fn (KeywordHit $h) => in_array($h->type, $types, true)));
    }

    /** @param list<KeywordHit> $hits */
    private function anyInField(array $hits, string $field): bool
    {
        foreach ($hits as $h) {
            if ($h->field === $field) {
                return true;
            }
        }

        return false;
    }

    /** @param list<KeywordHit> $hits */
    private function firstValue(array $hits, string $field): string
    {
        foreach ($hits as $h) {
            if ($h->field === $field) {
                return $h->value;
            }
        }

        return '';
    }

    /**
     * @param list<KeywordHit> $hits
     * @return list<array{value: string, type: string, field: string}>
     */
    private function toPayload(array $hits): array
    {
        return array_map(fn (KeywordHit $h) => [
            'value' => $h->value,
            'type' => $h->type->value,
            'field' => (string) $h->field,
        ], $hits);
    }
}
```

- [ ] **Step 5: Запустить весь файл — зелёный**

Run: `./vendor/bin/pest tests/Unit/Scoring/ScorerTest.php`
Expected: PASS (все кейсы).

- [ ] **Step 6: Pint + PHPStan**

Run: `./vendor/bin/pint app/Scoring tests/Unit/Scoring && ./vendor/bin/phpstan analyse app/Scoring`
Expected: no diff, `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add app/Scoring/Scorer.php tests/Unit/Scoring/ScorerTest.php composer.json
git commit -m "A4: Scorer — все правила §7.2 (текст, цена/bccomp, дедлайн, окпд, статус), clamp, уровни"
```

---

## Задача 4: HardFilter (§7.1)

**Files:**
- Create: `app/Scoring/HardFilter.php`
- Test: `tests/Unit/Scoring/HardFilterTest.php`

- [ ] **Step 1: Падающий тест**

Create `tests/Unit/Scoring/HardFilterTest.php`:

```php
<?php

use App\Enums\LawType;
use App\Models\Company;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Scoring\HardFilter;

function filterTender(array $overrides = []): Tender
{
    return Tender::factory()->make(array_merge([
        'source_id' => 1,
        'region' => 'Тверь',
        'price' => '1500000',
        'law_type' => LawType::Fz44,
        'owner_company_id' => null,
    ], $overrides));
}

function filterProfile(array $overrides = []): SearchProfile
{
    $profile = SearchProfile::factory()->make(array_merge([
        'company_id' => 7,
        'law_types' => [],
        'regions' => [],
        'excluded_regions' => [],
        'min_price' => null,
        'max_price' => null,
    ], $overrides));

    return $profile;
}

function passes(Tender $tender, SearchProfile $profile, array $enabledSourceIds = [1]): bool
{
    return app(HardFilter::class)->passes($tender, $profile, $enabledSourceIds);
}

it('пустой law_types пропускает любой закон, включая other', function () {
    expect(passes(filterTender(['law_type' => LawType::Other]), filterProfile()))->toBeTrue();
});

it('непустой law_types: проходит только закон из списка', function () {
    $profile = filterProfile(['law_types' => ['fz44']]);
    expect(passes(filterTender(['law_type' => LawType::Fz44]), $profile))->toBeTrue();
    expect(passes(filterTender(['law_type' => LawType::Fz223]), $profile))->toBeFalse();
});

it('other проходит только при пустом law_types', function () {
    $profile = filterProfile(['law_types' => ['fz44', 'fz223', 'commercial']]);
    expect(passes(filterTender(['law_type' => LawType::Other]), $profile))->toBeFalse();
});

it('регион из excluded_regions отсекает', function () {
    $profile = filterProfile(['excluded_regions' => ['Тверь']]);
    expect(passes(filterTender(['region' => 'Тверь']), $profile))->toBeFalse();
    expect(passes(filterTender(['region' => 'Москва']), $profile))->toBeTrue();
});

it('ценовой фильтр срабатывает только когда заданы ОБЕ границы и price NOT NULL', function () {
    $bounded = filterProfile(['min_price' => '1000000', 'max_price' => '2000000']);
    expect(passes(filterTender(['price' => '1500000']), $bounded))->toBeTrue();
    expect(passes(filterTender(['price' => '2500000']), $bounded))->toBeFalse();
    expect(passes(filterTender(['price' => '500000']), $bounded))->toBeFalse();
    // price NULL не отсекается жёстким фильтром
    expect(passes(filterTender(['price' => null]), $bounded))->toBeTrue();
    // задана только одна граница → ценового жёсткого фильтра нет
    $oneSide = filterProfile(['min_price' => '1000000', 'max_price' => null]);
    expect(passes(filterTender(['price' => '500000']), $oneSide))->toBeTrue();
});

it('источник вне включённых отсекает', function () {
    expect(passes(filterTender(['source_id' => 1]), filterProfile(), [1, 2]))->toBeTrue();
    expect(passes(filterTender(['source_id' => 9]), filterProfile(), [1, 2]))->toBeFalse();
});

it('manual-закупка чужого владельца отсекается; своя — проходит', function () {
    $profile = filterProfile(['company_id' => 7]);
    expect(passes(filterTender(['owner_company_id' => 7]), $profile))->toBeTrue();
    expect(passes(filterTender(['owner_company_id' => 99]), $profile))->toBeFalse();
    // глобальная (owner null) проходит
    expect(passes(filterTender(['owner_company_id' => null]), $profile))->toBeTrue();
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Unit/Scoring/HardFilterTest.php`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализовать HardFilter**

Create `app/Scoring/HardFilter.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\LawType;
use App\Models\SearchProfile;
use App\Models\Tender;

final class HardFilter
{
    /**
     * Жёсткие фильтры §7.1. Непрошедшие не скорятся.
     *
     * @param list<int> $enabledSourceIds  включённые у компании источники (+ manual)
     */
    public function passes(Tender $tender, SearchProfile $profile, array $enabledSourceIds): bool
    {
        // Источник ∈ включённых
        if (! in_array((int) $tender->source_id, $enabledSourceIds, true)) {
            return false;
        }

        // Владелец manual-закупки (Р13): приватная закупка матчится только владельцем
        if ($tender->owner_company_id !== null && (int) $tender->owner_company_id !== (int) $profile->company_id) {
            return false;
        }

        // law_type: пустой список = любой закон; other проходит только при пустом
        $lawTypes = $profile->law_types ?? [];
        if ($lawTypes !== []) {
            if ($tender->law_type === LawType::Other) {
                return false;
            }
            if (! in_array($tender->law_type->value, $lawTypes, true)) {
                return false;
            }
        }

        // Регион ∉ excluded_regions
        $excluded = $profile->excluded_regions ?? [];
        if ($tender->region !== null && in_array($tender->region, $excluded, true)) {
            return false;
        }

        // Цена: фильтр только когда заданы ОБЕ границы и price NOT NULL
        $price = $tender->price;
        $min = $profile->min_price;
        $max = $profile->max_price;
        if ($price !== null && $min !== null && $max !== null) {
            if (bccomp($price, $min, 2) < 0 || bccomp($price, $max, 2) > 0) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 4: Запустить — зелёный; Pint + PHPStan**

Run: `./vendor/bin/pest tests/Unit/Scoring/HardFilterTest.php && ./vendor/bin/pint app/Scoring tests/Unit/Scoring && ./vendor/bin/phpstan analyse app/Scoring`
Expected: PASS; no diff; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add app/Scoring/HardFilter.php tests/Unit/Scoring/HardFilterTest.php
git commit -m "A4: HardFilter — жёсткие фильтры §7.1 (закон, регион, цена/bccomp, источник, владелец manual)"
```

---

## Задача 5: CandidateQuery (предфильтр §7.6)

**Files:**
- Create: `app/Scoring/CandidateQuery.php`
- Test: `tests/Feature/Scoring/CandidateQueryTest.php`

- [ ] **Step 1: Падающий фичевый тест**

Create `tests/Feature/Scoring/CandidateQueryTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Models\Company;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Scoring\CandidateQuery;

function buildCandidates(SearchProfile $profile, array $enabledSourceIds, ?string $since = null)
{
    return app(CandidateQuery::class)
        ->build($profile, $profile->keywords()->get(), $enabledSourceIds, $since ? \Illuminate\Support\Carbon::parse($since) : null)
        ->pluck('id');
}

it('FTS-ветка ловит морфологию (склонение) по non-exact ключу', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    // 'краны' (5 симв., >4) идёт ТОЛЬКО в FTS-ветку (не в ILIKE), и substring 'краны' НЕ содержится
    // в 'кранов' — значит хит ловится исключительно стеммером, ветка изолирована.
    Keyword::factory()->for($profile)->create(['value' => 'краны', 'type' => KeywordType::Main]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов мостовых', 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка насосов', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('trigram/ILIKE-ветка ловит аббревиатуру в названии (минует FTS)', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'КМД', 'type' => KeywordType::Main]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Изготовление КМД для каркаса', 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Ремонт кровли', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('exact-фраза попадает в ILIKE-ветку по названию', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'краны мостовые', 'type' => KeywordType::Exact]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка: краны мостовые опорные', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('owner-scope: видны глобальные и свои manual, чужие manual — нет', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $global = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);
    $mine = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => $company->id]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => $other->id]);

    expect(buildCandidates($profile, [$source->id])->sort()->values()->all())->toBe([$global->id, $mine->id]);
});

it('source-scope: закупка из не-включённого источника не кандидат', function () {
    $enabled = Source::factory()->create();
    $disabled = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $hit = Tender::factory()->create(['source_id' => $enabled->id, 'title' => 'Краны', 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $disabled->id, 'title' => 'Краны', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$enabled->id])->all())->toBe([$hit->id]);
});

it('since-фильтр инкрементального матчинга по updated_at', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $old = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);
    $old->forceFill(['updated_at' => now()->subDays(3)])->save();
    $fresh = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id], now()->subDay()->toDateTimeString())->all())->toBe([$fresh->id]);
});

it('без включённых источников — нет кандидатов', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [])->all())->toBe([]);
});

it('фильтр deadline_min_days (§4.2): закупка с близким дедлайном не кандидат', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create(['deadline_min_days' => 7]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $ok = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->addDays(10), 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->addDays(3), 'owner_company_id' => null]);
    $noDeadline = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => null, 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->sort()->values()->all())->toBe([$ok->id, $noDeadline->id]);
});

it('просроченный дедлайн отсекается при deadline_min_days=0 (дефолт)', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create(['deadline_min_days' => 0]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    $future = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->addDay(), 'owner_company_id' => null]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Краны', 'deadline_at' => now()->subDay(), 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$future->id]);
});

it('ILIKE-ветка нормализует ё→е и регистр (предфильтр не теряет кандидата под exact-фразу)', function () {
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'Ёлочные Гирлянды', 'type' => KeywordType::Exact]);

    $hit = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Закупка елочные гирлянды уличные', 'owner_company_id' => null]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([$hit->id]);
});

it('истинная слепая зона: exact-фраза только в описании НЕ становится кандидатом', function () {
    // Единственная положительная зацепка — exact-фраза, и только в описании. Exact идёт в ILIKE-ветку
    // (только title), в FTS-ветку не попадает → кандидата нет. Это задокументированный компромисс §7.6.
    $source = Source::factory()->create();
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    Keyword::factory()->for($profile)->create(['value' => 'аренда спецтехники', 'type' => KeywordType::Exact]);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка строительных материалов',
        'description' => 'в составе аренда спецтехники на объекте',
        'owner_company_id' => null,
    ]);

    expect(buildCandidates($profile, [$source->id])->all())->toBe([]);
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Feature/Scoring/CandidateQueryTest.php`
Expected: FAIL — `Class "App\Scoring\CandidateQuery" not found`.

- [ ] **Step 3: Реализовать CandidateQuery**

Create `app/Scoring/CandidateQuery.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CandidateQuery
{
    /**
     * Предфильтр кандидатов §7.6: FTS-ветка (не-exact ключи через search_vector) OR
     * trigram/ILIKE-ветка по title (exact-фразы и аббревиатуры ≤4 симв.), плюс
     * owner-scope (глобальные + свои manual) и включённые источники.
     *
     * @param Collection<int, Keyword> $keywords
     * @param list<int> $enabledSourceIds
     * @return Builder<Tender>
     */
    public function build(
        SearchProfile $profile,
        Collection $keywords,
        array $enabledSourceIds,
        ?CarbonInterface $sinceUpdatedAt = null,
    ): Builder {
        /** @var list<string> $ftsValues */
        $ftsValues = [];
        /** @var list<string> $ilikeValues */
        $ilikeValues = [];

        foreach ($keywords as $kw) {
            if ($kw->type === KeywordType::Negative) {
                continue;
            }
            if ($kw->type === KeywordType::Exact) {
                $ilikeValues[] = $kw->value;

                continue;
            }
            $ftsValues[] = $kw->value;
            if (mb_strlen(Canonicalizer::text($kw->value)) <= 4) {
                $ilikeValues[] = $kw->value; // короткие аббревиатуры — слепая зона FTS
            }
        }

        $companyId = (int) $profile->company_id;
        $deadlineMinDays = (int) ($profile->deadline_min_days ?? 0);

        $query = Tender::query()
            ->where(function (Builder $q) use ($companyId) {
                $q->whereNull('owner_company_id')->orWhere('owner_company_id', $companyId);
            })
            ->whereIn('source_id', $enabledSourceIds)
            // §4.2 шаг 2: кандидатный фильтр дедлайна (НЕ §7.1). Дефолт 0 → отсекаются просроченные.
            ->where(function (Builder $q) use ($deadlineMinDays) {
                $q->whereNull('deadline_at')
                    ->orWhereRaw('deadline_at >= now() + make_interval(days => ?)', [$deadlineMinDays]);
            })
            ->when($sinceUpdatedAt !== null, fn (Builder $q) => $q->where('updated_at', '>=', $sinceUpdatedAt));

        $query->where(function (Builder $q) use ($ftsValues, $ilikeValues) {
            if ($ftsValues === [] && $ilikeValues === []) {
                $q->whereRaw('1 = 0'); // профиль без положительных ключей — кандидатов нет

                return;
            }
            foreach ($ftsValues as $value) {
                $q->orWhereRaw("search_vector @@ plainto_tsquery('russian', ?)", [$value]);
            }
            foreach ($ilikeValues as $value) {
                // Нормализуем title и значение как Canonicalizer (lower + ё→е), чтобы предфильтр
                // не терял кандидатов, которые KeywordMatchResolver затем подтвердит.
                $q->orWhereRaw("replace(lower(title), 'ё', 'е') LIKE ?", ['%'.$this->normalizedLike($value).'%']);
            }
        });

        return $query;
    }

    /** lower + ё→е (как Canonicalizer) + экранирование спецсимволов LIKE. */
    private function normalizedLike(string $value): string
    {
        $normalized = str_replace('ё', 'е', mb_strtolower($value));

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);
    }
}
```

- [ ] **Step 4: Запустить — зелёный; Pint + PHPStan**

Run: `./vendor/bin/pest tests/Feature/Scoring/CandidateQueryTest.php && ./vendor/bin/pint app/Scoring tests/Feature/Scoring && ./vendor/bin/phpstan analyse app/Scoring`
Expected: PASS (7 тестов); no diff; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add app/Scoring/CandidateQuery.php tests/Feature/Scoring/CandidateQueryTest.php
git commit -m "A4: CandidateQuery — предфильтр FTS+trigram §7.6 (owner/source-scope, since)"
```

---

## Задача 6: KeywordMatchResolver (полевое попадание ключей)

**Files:**
- Create: `app/Scoring/KeywordMatchResolver.php`
- Test: `tests/Feature/Scoring/KeywordMatchResolverTest.php`

- [ ] **Step 1: Падающий фичевый тест**

Create `tests/Feature/Scoring/KeywordMatchResolverTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Scoring\KeywordHit;
use App\Scoring\KeywordMatchResolver;

function resolveHits(Tender $tender, SearchProfile $profile): array
{
    return app(KeywordMatchResolver::class)->resolve($tender, $profile->keywords()->get());
}

/** @param list<KeywordHit> $hits */
function hitField(array $hits, string $value): ?string
{
    foreach ($hits as $h) {
        if ($h->value === $value) {
            return $h->field;
        }
    }

    return 'НЕ НАЙДЕН';
}

it('non-exact ключ матчится морфологически и относит к названию (приоритет над описанием)', function () {
    $profile = SearchProfile::factory()->create();
    // 'краны' (>4): substring 'краны' НЕ содержится в 'кранов' → попадание в title даёт именно стеммер.
    Keyword::factory()->for($profile)->create(['value' => 'краны', 'type' => KeywordType::Main]);

    $tender = Tender::factory()->create([
        'title' => 'Поставка кранов мостовых', // склонение в названии
        'description' => 'краны дополнительно в описании',
    ]);

    $hits = resolveHits($tender, $profile)['positive'];
    expect(hitField($hits, 'краны'))->toBe('title');
});

it('non-exact ключ только в описании → field=description', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'насосы', 'type' => KeywordType::Broad]);

    $tender = Tender::factory()->create([
        'title' => 'Поставка оборудования',
        'description' => 'комплект насосов промышленных', // стеммер russian: насосов→насос, насосы→насос
    ]);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'насосы'))->toBe('description');
});

it('non-exact ключ не найден → field=null', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'компьютеры', 'type' => KeywordType::Main]);

    $tender = Tender::factory()->create(['title' => 'Ремонт кровли', 'description' => 'кровельные работы']);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'компьютеры'))->toBeNull();
});

it('exact-фраза требует смежного вхождения (нормализованный substring)', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'краны мостовые', 'type' => KeywordType::Exact]);

    $contiguous = Tender::factory()->create(['title' => 'Поставка краны мостовые опорные', 'description' => null]);
    $scattered = Tender::factory()->create(['title' => 'Краны и фермы мостовые', 'description' => null]);

    expect(hitField(resolveHits($contiguous, $profile)['positive'], 'краны мостовые'))->toBe('title')
        ->and(hitField(resolveHits($scattered, $profile)['positive'], 'краны мостовые'))->toBeNull();
});

it('exact-фраза нормализуется (NBSP/регистр/ё)', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'ёлочные  ГИРЛЯНДЫ', 'type' => KeywordType::Exact]);

    $tender = Tender::factory()->create(['title' => 'Закупка елочные гирлянды уличные', 'description' => null]);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'ёлочные  ГИРЛЯНДЫ'))->toBe('title');
});

it('аббревиатура (≤4) ловится substring даже когда стеммер бы её выкинул', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'КМД', 'type' => KeywordType::Main]);

    $tender = Tender::factory()->create(['title' => 'Изготовление КМД', 'description' => null]);

    expect(hitField(resolveHits($tender, $profile)['positive'], 'КМД'))->toBe('title');
});

it('негативные ключи попадают в отдельный список с полевой атрибуцией', function () {
    $profile = SearchProfile::factory()->create();
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);
    Keyword::factory()->for($profile)->create(['value' => 'бывшие в употреблении', 'type' => KeywordType::Negative]);

    $tender = Tender::factory()->create([
        'title' => 'Поставка кранов',
        'description' => 'оборудование бывшие в употреблении',
    ]);

    $resolved = resolveHits($tender, $profile);
    expect(hitField($resolved['positive'], 'кран'))->toBe('title')
        ->and(hitField($resolved['negative'], 'бывшие в употреблении'))->toBe('description');
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Feature/Scoring/KeywordMatchResolverTest.php`
Expected: FAIL — класс не найден.

- [ ] **Step 3: Реализовать KeywordMatchResolver**

Create `app/Scoring/KeywordMatchResolver.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class KeywordMatchResolver
{
    /**
     * Определяет поле попадания каждого ключа в закупке:
     *  - exact: смежный нормализованный substring (title приоритетнее description);
     *  - не-exact: морфология через plainto_tsquery('russian') ИЛИ substring (для аббревиатур).
     *
     * @param Collection<int, Keyword> $keywords
     * @return array{positive: list<KeywordHit>, negative: list<KeywordHit>}
     */
    public function resolve(Tender $tender, Collection $keywords): array
    {
        $titleCanon = Canonicalizer::text($tender->title);
        $descCanon = Canonicalizer::text($tender->description);

        /** @var list<string> $ftsValues */
        $ftsValues = $keywords
            ->reject(fn (Keyword $k) => $k->type === KeywordType::Exact)
            ->map(fn (Keyword $k) => $k->value)
            ->values()
            ->all();

        $ftsMap = $this->ftsHits($tender, $ftsValues);

        $positive = [];
        $negative = [];

        foreach ($keywords as $kw) {
            $field = $this->fieldFor($kw, $titleCanon, $descCanon, $ftsMap);
            $hit = new KeywordHit($kw->value, $kw->type, $field);
            if ($kw->type === KeywordType::Negative) {
                $negative[] = $hit;
            } else {
                $positive[] = $hit;
            }
        }

        return ['positive' => $positive, 'negative' => $negative];
    }

    /**
     * @param array<string, array{title: bool, desc: bool}> $ftsMap
     * @return 'title'|'description'|null
     */
    private function fieldFor(Keyword $kw, string $titleCanon, string $descCanon, array $ftsMap): ?string
    {
        $valueCanon = Canonicalizer::text($kw->value);
        $subTitle = $valueCanon !== '' && str_contains($titleCanon, $valueCanon);
        $subDesc = $valueCanon !== '' && str_contains($descCanon, $valueCanon);

        if ($kw->type === KeywordType::Exact) {
            return $subTitle ? 'title' : ($subDesc ? 'description' : null);
        }

        $fts = $ftsMap[$kw->value] ?? ['title' => false, 'desc' => false];
        $titleHit = $subTitle || $fts['title'];
        $descHit = $subDesc || $fts['desc'];

        return $titleHit ? 'title' : ($descHit ? 'description' : null);
    }

    /**
     * Морфологическое попадание каждого значения в title/description через стеммер.
     * Один запрос на закупку: tsvector названия/описания считается в CTE один раз.
     *
     * @param list<string> $values
     * @return array<string, array{title: bool, desc: bool}>
     */
    private function ftsHits(Tender $tender, array $values): array
    {
        $values = array_values(array_unique($values));
        if ($values === []) {
            return [];
        }

        $columns = [];
        $bindings = [$tender->title ?? '', $tender->description ?? ''];
        foreach ($values as $i => $value) {
            $columns[] = "(v.tv_title @@ plainto_tsquery('russian', ?))::int AS t{$i}";
            $columns[] = "(v.tv_desc @@ plainto_tsquery('russian', ?))::int AS d{$i}";
            $bindings[] = $value;
            $bindings[] = $value;
        }

        $sql = "WITH v AS (SELECT to_tsvector('russian', coalesce(?, '')) AS tv_title, "
            ."to_tsvector('russian', coalesce(?, '')) AS tv_desc) SELECT "
            .implode(', ', $columns).' FROM v';

        $row = DB::selectOne($sql, $bindings);

        $map = [];
        foreach ($values as $i => $value) {
            $map[$value] = [
                'title' => (bool) ($row->{"t{$i}"} ?? 0),
                'desc' => (bool) ($row->{"d{$i}"} ?? 0),
            ];
        }

        return $map;
    }
}
```

- [ ] **Step 4: Запустить — зелёный; Pint + PHPStan**

Run: `./vendor/bin/pest tests/Feature/Scoring/KeywordMatchResolverTest.php && ./vendor/bin/pint app/Scoring tests/Feature/Scoring && ./vendor/bin/phpstan analyse app/Scoring`
Expected: PASS (7 тестов); no diff; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add app/Scoring/KeywordMatchResolver.php tests/Feature/Scoring/KeywordMatchResolverTest.php
git commit -m "A4: KeywordMatchResolver — морфология (FTS) + substring exact/аббревиатур, полевая атрибуция"
```

---

## Задача 7: MatchProfileService (оркестрация + персист)

**Files:**
- Create: `app/Scoring/MatchProfileService.php`
- Test: `tests/Feature/Scoring/MatchProfileServiceTest.php`

- [ ] **Step 1: Падающий фичевый тест — сквозной матч и персист**

Create `tests/Feature/Scoring/MatchProfileServiceTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\CompanyTender;
use App\Models\Keyword;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-13 12:00:00');
    // Источник manual должен существовать (его сервис добавляет к включённым автоматически).
    Source::factory()->create(['code' => 'manual']);
});

afterEach(fn () => Carbon::setTestNow());

function activeProfile(Company $company, array $keywords): SearchProfile
{
    $profile = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    foreach ($keywords as [$value, $type]) {
        Keyword::factory()->for($profile)->create(['value' => $value, 'type' => $type]);
    }

    return $profile->refresh();
}

function enableSource(Company $company, Source $source): void
{
    CompanySource::withoutTenancy()->create([
        'company_id' => $company->id,
        'source_id' => $source->id,
        'is_active' => true,
    ]);
}

function runMatch(SearchProfile $profile, RunTrigger $trigger = RunTrigger::Import): RadarRun
{
    return app(MatchProfileService::class)->run($profile, $trigger);
}

it('создаёт матч и проекцию company_tender по релевантной закупке', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);

    $tender = Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка кранов мостовых',
        'price' => '1500000',
        'deadline_at' => now()->addDays(3), // нейтральная зона: без deadline_far_bonus (фабрика по умолчанию +10 дней!)
        'owner_company_id' => null,
    ]);

    $run = runMatch($profile);

    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->matched_count)->toBe(1)
        ->and($run->processed_count)->toBe(1);

    $match = TenderMatch::withoutTenancy()->sole();
    expect($match->company_id)->toBe($company->id)
        ->and($match->search_profile_id)->toBe($profile->id)
        ->and($match->radar_run_id)->toBe($run->id)
        ->and($match->score)->toBe(20)
        ->and($match->matched_keywords)->toBe([
            ['value' => 'кран', 'type' => 'main', 'field' => 'title'],
        ]);

    $ct = CompanyTender::withoutTenancy()->sole();
    expect($ct->best_score)->toBe(20)
        ->and($ct->best_level)->toBe(RelevanceLevel::Low)
        ->and($ct->first_matched_at)->not->toBeNull();
});

it('не матчит закупку без положительного попадания ключа', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);

    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Ремонт кровли', 'owner_company_id' => null]);

    $run = runMatch($profile);
    expect($run->matched_count)->toBe(0)
        ->and(TenderMatch::withoutTenancy()->count())->toBe(0)
        ->and(CompanyTender::withoutTenancy()->count())->toBe(0);
});

it('повторный прогон обновляет матч, а не плодит дубль; first_matched_at сохраняется', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    runMatch($profile);
    $firstMatchedAt = CompanyTender::withoutTenancy()->sole()->first_matched_at;

    Carbon::setTestNow('2026-06-20 12:00:00');
    runMatch($profile);

    expect(TenderMatch::withoutTenancy()->count())->toBe(1)
        ->and(CompanyTender::withoutTenancy()->count())->toBe(1)
        ->and(CompanyTender::withoutTenancy()->sole()->first_matched_at->toDateTimeString())
        ->toBe($firstMatchedAt->toDateTimeString());
});

it('best_* = максимум по нескольким профилям одной компании', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);

    $tender = Tender::factory()->create([
        'source_id' => $source->id,
        // Именительная форма фразы: exact матчится ТОЛЬКО смежным substring (не морфологически).
        'title' => 'Поставка: краны мостовые опорные',
        'price' => '2000000',
        'region' => 'Москва',
        'deadline_at' => now()->addDays(3), // нейтральный дедлайн: без +10 (иначе strong=70, weak=30)
        'owner_company_id' => null,
    ]);

    $weak = activeProfile($company, [['кран', KeywordType::Main]]); // keyword_in_title 20
    $strong = activeProfile($company, [['краны мостовые', KeywordType::Exact]]);
    $strong->update(['min_price' => '1000000', 'regions' => ['Москва']]);

    runMatch($weak);
    runMatch($strong->refresh());

    expect(TenderMatch::withoutTenancy()->where('tender_id', $tender->id)->count())->toBe(2);
    $ct = CompanyTender::withoutTenancy()->where('tender_id', $tender->id)->sole();
    // strong: exact_in_title 30 + price_above_min 20 + priority_region 10 = 60 > weak 20
    expect($ct->best_score)->toBe(60)->and($ct->best_level)->toBe(RelevanceLevel::Medium);
});

it('исключение по негативу в названии: матч не создаётся; существующий удаляется, best обнуляется', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($company, $source);
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);
    $profile->update(['exclude_on_negative_in_title' => true]);
    Keyword::factory()->for($profile)->create(['value' => 'бу', 'type' => KeywordType::Negative]);

    $tender = Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    // Первый прогон — матч есть.
    runMatch($profile->refresh());
    expect(TenderMatch::withoutTenancy()->count())->toBe(1);

    // Название меняется — появляется минус-слово в названии.
    $tender->update(['title' => 'Поставка кранов бу']);
    runMatch($profile->refresh());

    expect(TenderMatch::withoutTenancy()->count())->toBe(0)
        ->and(CompanyTender::withoutTenancy()->sole()->best_score)->toBe(0);
});

it('manual-источник включён неявно: своя импортированная закупка матчится без company_sources', function () {
    $company = Company::factory()->create();
    $manual = Source::query()->where('code', 'manual')->sole();
    // company_sources НЕ создаём — manual должен добавиться автоматически.
    $profile = activeProfile($company, [['кран', KeywordType::Main]]);
    Tender::factory()->create(['source_id' => $manual->id, 'title' => 'Поставка кранов', 'owner_company_id' => $company->id]);

    $run = runMatch($profile);
    expect($run->matched_count)->toBe(1);
});

it('работает без TenantContext (как в джобе) и не протекает между компаниями', function () {
    app(TenantContext::class)->set(null);

    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $source = Source::factory()->create();
    enableSource($companyA, $source);
    enableSource($companyB, $source);

    $profileA = activeProfile($companyA, [['кран', KeywordType::Main]]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    runMatch($profileA);

    expect(TenderMatch::withoutTenancy()->where('company_id', $companyA->id)->count())->toBe(1)
        ->and(TenderMatch::withoutTenancy()->where('company_id', $companyB->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Feature/Scoring/MatchProfileServiceTest.php`
Expected: FAIL — `Class "App\Scoring\MatchProfileService" not found`.

- [ ] **Step 3: Реализовать MatchProfileService**

Create `app/Scoring/MatchProfileService.php`:

```php
<?php

namespace App\Scoring;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\CompanySource;
use App\Models\CompanyTender;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Enums\RelevanceLevel;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MatchProfileService
{
    public function __construct(
        private readonly CandidateQuery $candidates,
        private readonly KeywordMatchResolver $resolver,
        private readonly Scorer $scorer,
        private readonly HardFilter $hardFilter,
    ) {}

    /**
     * Прогоняет профиль по кандидатам, скорит и персистит результат в рамках radar_run.
     * Не полагается на TenantContext — все запросы используют явный company_id.
     */
    public function run(SearchProfile $profile, RunTrigger $trigger, ?CarbonInterface $sinceUpdatedAt = null): RadarRun
    {
        $companyId = (int) $profile->company_id;
        $enabledSourceIds = $this->enabledSourceIds($companyId);

        $run = RadarRun::create([
            'company_id' => $companyId,
            'search_profile_id' => $profile->id,
            'trigger' => $trigger,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        /** @var Collection<int, \App\Models\Keyword> $keywords */
        $keywords = $profile->keywords; // relation-свойство: переиспользует with('keywords') из джоба (без лишнего SELECT); при прямом вызове из теста лениво загрузит один раз
        $weights = ScoringWeights::for($profile);

        $processed = 0;
        $matched = 0;
        $errors = 0;
        $errorLines = [];

        $this->candidates->build($profile, $keywords, $enabledSourceIds, $sinceUpdatedAt)
            ->chunkById(500, function (Collection $tenders) use (
                &$processed, &$matched, &$errors, &$errorLines, $profile, $keywords, $weights, $companyId, $enabledSourceIds, $run
            ): void {
                foreach ($tenders as $tender) {
                    $processed++;
                    try {
                        // $enabledSourceIds вычислен один раз до цикла — не пересчитываем в чанке (N+1).
                        if (! $this->hardFilter->passes($tender, $profile, $enabledSourceIds)) {
                            continue;
                        }

                        ['positive' => $positive, 'negative' => $negative] = $this->resolver->resolve($tender, $keywords);

                        // "положительное" здесь = любой сработавший НЕ-негативный ключ
                        // (main/exact/broad/high/low), НЕ KeywordType::isPositive(). См. решение №8.
                        $hasPositive = false;
                        foreach ($positive as $hit) {
                            if ($hit->matched()) {
                                $hasPositive = true;
                                break;
                            }
                        }
                        if (! $hasPositive) {
                            continue;
                        }

                        $result = $this->scorer->score($tender, $profile, $weights, $positive, $negative);

                        if ($result->excluded) {
                            $this->removeMatch($companyId, (int) $tender->id, (int) $profile->id);

                            continue;
                        }

                        $this->persist($companyId, (int) $tender->id, (int) $profile->id, (int) $run->id, $result);
                        $matched++;
                    } catch (Throwable $e) {
                        $errors++;
                        $errorLines[] = "tender {$tender->id}: ".mb_substr($e->getMessage(), 0, 200);
                    }
                }
            });

        $run->update([
            'status' => RunStatus::Ok,
            'finished_at' => now(),
            'processed_count' => $processed,
            'matched_count' => $matched,
            'error_count' => $errors,
            'log' => $errorLines !== [] ? implode("\n", array_slice($errorLines, 0, 100)) : null,
        ]);

        return $run->refresh();
    }

    /** @return list<int> */
    private function enabledSourceIds(int $companyId): array
    {
        $ids = CompanySource::withoutTenancy()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // manual всегда доступен: компания импортирует собственные данные.
        $manualId = Source::query()->where('code', 'manual')->value('id');
        if ($manualId !== null && ! in_array((int) $manualId, $ids, true)) {
            $ids[] = (int) $manualId;
        }

        return $ids;
    }

    private function persist(int $companyId, int $tenderId, int $profileId, int $runId, ScoreResult $result): void
    {
        DB::transaction(function () use ($companyId, $tenderId, $profileId, $runId, $result): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['ct:'.$companyId.':'.$tenderId]);

            TenderMatch::withoutTenancy()->updateOrCreate(
                ['search_profile_id' => $profileId, 'tender_id' => $tenderId],
                [
                    'company_id' => $companyId,
                    'radar_run_id' => $runId,
                    'score' => $result->score,
                    'level' => $result->level,
                    'reason' => $result->reason,
                    'matched_keywords' => $result->matchedKeywords,
                    'matched_negative_keywords' => $result->matchedNegativeKeywords,
                    'risk_flags' => $result->riskFlags,
                    'action_recommendation' => $result->recommendation,
                ],
            );

            $this->recomputeBest($companyId, $tenderId, createIfMissing: true);
        });
    }

    private function removeMatch(int $companyId, int $tenderId, int $profileId): void
    {
        DB::transaction(function () use ($companyId, $tenderId, $profileId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['ct:'.$companyId.':'.$tenderId]);

            TenderMatch::withoutTenancy()
                ->where('search_profile_id', $profileId)
                ->where('tender_id', $tenderId)
                ->delete();

            $this->recomputeBest($companyId, $tenderId, createIfMissing: false);
        });
    }

    /**
     * Пересчёт best_* по MAX(score) всех матчей пары (company, tender). Явный company_id,
     * без TenantContext/CompanyTender::matches(). first_matched_at ставится только при создании.
     */
    private function recomputeBest(int $companyId, int $tenderId, bool $createIfMissing): void
    {
        $best = (int) TenderMatch::withoutTenancy()
            ->where('company_id', $companyId)
            ->where('tender_id', $tenderId)
            ->max('score');

        $level = RelevanceLevel::fromScore($best);

        $existing = CompanyTender::withoutTenancy()
            ->where('company_id', $companyId)
            ->where('tender_id', $tenderId)
            ->first();

        if ($existing !== null) {
            $existing->update(['best_score' => $best, 'best_level' => $level]);

            return;
        }

        if ($createIfMissing) {
            CompanyTender::create([
                'company_id' => $companyId,
                'tender_id' => $tenderId,
                'best_score' => $best,
                'best_level' => $level,
                'first_matched_at' => now(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Запустить — зелёный; Pint + PHPStan**

Run: `./vendor/bin/pest tests/Feature/Scoring/MatchProfileServiceTest.php && ./vendor/bin/pint app/Scoring tests/Feature/Scoring && ./vendor/bin/phpstan analyse app/Scoring`
Expected: PASS (7 тестов); no diff; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add app/Scoring/MatchProfileService.php tests/Feature/Scoring/MatchProfileServiceTest.php
git commit -m "A4: MatchProfileService — оркестрация прогона, персист матчей, пересчёт best_* (advisory-lock, first_matched_at)"
```

---

## Задача 8: MatchProfileJob

**Files:**
- Create: `app/Jobs/MatchProfileJob.php`
- Test: `tests/Feature/Scoring/MatchProfileJobTest.php`

- [ ] **Step 1: Падающий фичевый тест**

Create `tests/Feature/Scoring/MatchProfileJobTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Keyword;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;

beforeEach(function () {
    Source::factory()->create(['code' => 'manual']);
});

it('исполняет матчинг активного профиля по id', function () {
    $company = Company::factory()->create();
    $source = Source::factory()->create();
    CompanySource::withoutTenancy()->create(['company_id' => $company->id, 'source_id' => $source->id, 'is_active' => true]);
    $profile = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);
    Tender::factory()->create(['source_id' => $source->id, 'title' => 'Поставка кранов', 'owner_company_id' => null]);

    (new MatchProfileJob($profile->id, RunTrigger::Import->value))->handle(app(\App\Scoring\MatchProfileService::class));

    expect(RadarRun::withoutTenancy()->where('search_profile_id', $profile->id)->count())->toBe(1);
});

it('тихо выходит на удалённом профиле', function () {
    (new MatchProfileJob(999999, RunTrigger::Import->value))->handle(app(\App\Scoring\MatchProfileService::class));

    expect(RadarRun::withoutTenancy()->count())->toBe(0);
});

it('тихо выходит на неактивном профиле', function () {
    $profile = SearchProfile::factory()->create(['is_active' => false]);

    (new MatchProfileJob($profile->id, RunTrigger::Import->value))->handle(app(\App\Scoring\MatchProfileService::class));

    expect(RadarRun::withoutTenancy()->count())->toBe(0);
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Feature/Scoring/MatchProfileJobTest.php`
Expected: FAIL — `Class "App\Jobs\MatchProfileJob" not found`.

- [ ] **Step 3: Реализовать MatchProfileJob**

Create `app/Jobs/MatchProfileJob.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\RunTrigger;
use App\Models\SearchProfile;
use App\Scoring\MatchProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class MatchProfileJob implements ShouldQueue
{
    use Queueable;

    /** Передаём скаляры (не модели) ради безопасной сериализации в очередь. */
    public function __construct(
        public int $searchProfileId,
        public string $trigger,
        public ?string $sinceUpdatedAt = null,
    ) {}

    public function handle(MatchProfileService $service): void
    {
        $profile = SearchProfile::withoutTenancy()
            ->with('keywords')
            ->find($this->searchProfileId);

        if ($profile === null || ! $profile->is_active) {
            return; // профиль удалён или деактивирован между диспатчем и исполнением
        }

        $service->run(
            $profile,
            RunTrigger::from($this->trigger),
            $this->sinceUpdatedAt !== null ? CarbonImmutable::parse($this->sinceUpdatedAt) : null,
        );
    }
}
```

- [ ] **Step 4: Запустить — зелёный; Pint + PHPStan**

Run: `./vendor/bin/pest tests/Feature/Scoring/MatchProfileJobTest.php && ./vendor/bin/pint app/Jobs tests/Feature/Scoring && ./vendor/bin/phpstan analyse app/Jobs`
Expected: PASS (3 теста); no diff; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/MatchProfileJob.php tests/Feature/Scoring/MatchProfileJobTest.php
git commit -m "A4: MatchProfileJob — очередная обёртка с гардом удалённого/неактивного профиля"
```

---

## Задача 9: Автоматчинг после ручного импорта

**Files:**
- Modify: `app/Services/Import/ManualImportService.php`
- Test: `tests/Feature/Scoring/ImportAutoMatchTest.php`

- [ ] **Step 1: Падающий фичевый тест — диспатч и сквозной матч**

Create `tests/Feature/Scoring/ImportAutoMatchTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\Company;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\TenderMatch;
use App\Services\Import\ManualImportService;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Source::factory()->create(['code' => 'manual']);
});

function importCsv(Company $company): void
{
    app(TenantContext::class)->set($company->id);
    $csv = "название;цена;ссылка\nПоставка кранов мостовых;1500000;https://example.com/t/1\n";
    $file = UploadedFile::fake()->createWithContent('import.csv', $csv);
    app(ManualImportService::class)->import($file, $company);
    app(TenantContext::class)->set(null); // воркер исполняет MatchProfileJob без auth-контекста — воспроизводим это
}

it('диспатчит MatchProfileJob только для активных профилей компании с trigger=import', function () {
    Queue::fake();

    $company = Company::factory()->create();
    $active = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    $inactive = SearchProfile::factory()->for($company)->create(['is_active' => false]);
    $otherCompany = SearchProfile::factory()->create(['is_active' => true]); // другая компания

    importCsv($company);

    Queue::assertPushed(MatchProfileJob::class, 1);
    Queue::assertPushed(MatchProfileJob::class, fn (MatchProfileJob $job) => $job->searchProfileId === $active->id
        && $job->trigger === RunTrigger::Import->value);
});

it('сквозной: импорт → синхронный матч создаёт TenderMatch', function () {
    Queue::fake(); // перехватываем, потом исполняем вручную — детерминизм без воркера

    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create(['is_active' => true]);
    Keyword::factory()->for($profile)->create(['value' => 'кран', 'type' => KeywordType::Main]);

    importCsv($company);

    // Эмулируем обработку очереди.
    Queue::assertPushed(MatchProfileJob::class, function (MatchProfileJob $job) {
        $job->handle(app(\App\Scoring\MatchProfileService::class));

        return true;
    });

    expect(TenderMatch::withoutTenancy()->where('company_id', $company->id)->count())->toBe(1);
});

it('импорт без активных профилей не диспатчит джобы', function () {
    Queue::fake();
    $company = Company::factory()->create();
    SearchProfile::factory()->for($company)->create(['is_active' => false]);

    importCsv($company);

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Запустить — упадёт**

Run: `./vendor/bin/pest tests/Feature/Scoring/ImportAutoMatchTest.php`
Expected: FAIL — `Queue::assertPushed` не находит джоб (диспатча ещё нет).

- [ ] **Step 3: Заменить плейсхолдер в ManualImportService**

Modify `app/Services/Import/ManualImportService.php`:

Добавить импорты в начало файла (после `use App\Models\Source;`):

```php
use App\Jobs\MatchProfileJob;
use App\Models\SearchProfile;
```

Заменить строку-плейсхолдер:

```php
        // A4: здесь диспатчится MatchProfileJob для активных профилей компании (trigger=import).
```

на:

```php
        // A4: автоматчинг — по активным профилям компании (trigger=import).
        SearchProfile::withoutTenancy()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $profileId) => MatchProfileJob::dispatch($profileId, RunTrigger::Import->value));
```

(Плейсхолдер стоит между блоком `try/catch` и `return $run->refresh();` — диспатч происходит только после успешного завершения прогона, т.к. при ошибке `catch` пробрасывает исключение и до этой строки управление не доходит.)

- [ ] **Step 4: Запустить — зелёный; полный прогон импорта не сломан**

Run: `./vendor/bin/pest tests/Feature/Scoring/ImportAutoMatchTest.php tests/Feature/Import`
Expected: PASS (новые 3 + все существующие тесты импорта зелёные).

- [ ] **Step 5: Pint + PHPStan**

Run: `./vendor/bin/pint app/Services/Import tests/Feature/Scoring && ./vendor/bin/phpstan analyse app/Services/Import`
Expected: no diff; `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Import/ManualImportService.php tests/Feature/Scoring/ImportAutoMatchTest.php
git commit -m "A4: автоматчинг после ручного импорта (диспатч MatchProfileJob для активных профилей)"
```

---

## Задача 10: EXPLAIN + golden-набор

**Files:**
- (опц.) Create: `database/migrations/2026_06_13_120000_add_match_candidate_indexes.php`
- Create: `tests/Fixtures/golden/profile_metal.json`
- Create: `tests/Feature/Scoring/GoldenSetTest.php`

- [ ] **Step 1: EXPLAIN кандидатного запроса на наполненной таблице**

Запустить ad-hoc проверку плана (в tinker или временном тесте), чтобы решить про composite-индекс (заметка ревью A1: одиночные `price`/`law_type` индексы планировщик может игнорировать — но матчер их в SQL не использует):

```bash
php artisan tinker --execute="
\App\Models\Tender::factory()->count(2000)->create(['owner_company_id' => null]);
\$sql = \App\Models\Tender::query()
  ->where(fn(\$q)=>\$q->whereNull('owner_company_id')->orWhere('owner_company_id', 1))
  ->whereIn('source_id', [1])
  ->whereRaw(\"search_vector @@ plainto_tsquery('russian', ?)\", ['кран'])
  ->toSql();
dump(\DB::select('EXPLAIN ANALYZE '.\$sql, [1, 1, 'кран']));
"
```

Ожидаемо: используется `tenders_search_vector_gin` (Bitmap Index Scan). Записать результат в коммит-сообщение/роадмап.

Также прогнать EXPLAIN для ILIKE-ветки нормализованного выражения (профиль с exact-фразой/аббревиатурой):

```bash
php artisan tinker --execute="
dump(\DB::select(\"EXPLAIN ANALYZE SELECT id FROM tenders WHERE replace(lower(title), 'ё', 'е') LIKE ?\", ['%металлоконструкции%']));
"
```

Существующий `tenders_title_trgm` (GIN trigram по СЫРОМУ title) это выражение НЕ покрывает — ожидаем `Seq Scan`.

- [ ] **Step 2: Решение об индексах**

Два независимых решения по EXPLAIN на наполненной таблице:
1. **Composite owner/source:** если виден дорогой `Seq Scan` по `owner_company_id`/`source_id` поверх GIN-результата — создать composite. Иначе **не добавляем** (GIN покрывает горячий путь; `owner_company_id`/`source_id` уже btree/FK-индексы из A1) — отметить в роадмапе «GIN достаточно».
2. **Функциональный trigram под нормализованную ILIKE-ветку:** если `Seq Scan` по `replace(lower(title),'ё','е') LIKE …` дорог на объёме — добавить функциональный GIN trigram. Для A4 (малые объёмы импорта) допустимо оставить Seq Scan и отметить решение; индекс добавляем только если EXPLAIN подтверждает деградацию.

Если решено добавить индекс(ы) — create `database/migrations/2026_06_13_120000_add_match_candidate_indexes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Состав определяется по EXPLAIN ANALYZE кандидатного запроса (A4, заметка ревью A1).
        // Раскомментировать только подтверждённые EXPLAIN индексы:
        // DB::statement('CREATE INDEX IF NOT EXISTS tenders_owner_source_idx ON tenders (owner_company_id, source_id)');
        // DB::statement("CREATE INDEX IF NOT EXISTS tenders_title_norm_trgm ON tenders USING GIN (replace(lower(title), 'ё', 'е') gin_trgm_ops)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenders_owner_source_idx');
        DB::statement('DROP INDEX IF EXISTS tenders_title_norm_trgm');
    }
};
```

- [ ] **Step 3: Golden-набор фикстур**

Create `tests/Fixtures/golden/profile_metal.json` — профиль «металлоконструкции» + 11 закупок. Каждый кейс **жёстко фиксирует все измерения** (дедлайн `+3 дня` = нейтральная зона, если правило дедлайна не тестируется; `+10` = дальний; `+1` = близкий; цена/регион/окпд/статус заданы явно), чтобы `expected_score` был однозначно вычислим по §7.2 и весам `config/scoring.php`. Баллы ниже пересчитаны вручную по финальной логике `Scorer`:

```json
{
  "profile": {
    "name": "Металлоконструкции",
    "min_price": "1000000.00",
    "max_price": null,
    "regions": ["Москва"],
    "excluded_regions": ["Чечня"],
    "okpd2_codes": ["25.11"],
    "law_types": ["fz44", "fz223"],
    "exclude_on_negative_in_title": true,
    "keywords": [
      {"value": "металлоконструкции", "type": "main"},
      {"value": "изготовление металлоконструкций", "type": "exact"},
      {"value": "КМД", "type": "main"},
      {"value": "монтаж", "type": "broad"},
      {"value": "срочно", "type": "high_priority"},
      {"value": "доставка", "type": "low_priority"},
      {"value": "бу", "type": "negative"},
      {"value": "бывшие в употреблении", "type": "negative"}
    ]
  },
  "cases": [
    {
      "note": "#0 exact_in_title 30 + keyword_in_title 20 (main 'металлоконструкции' морфологически в названии) + price 20 + region 10 + okpd 5 = 85",
      "title": "Изготовление металлоконструкций для склада",
      "description": "комплект оборудования для объекта",
      "price": "5000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": ["25.11.23.000"], "deadline_offset_days": 3, "source_status": "active",
      "expected_score": 85, "expected_level": "high"
    },
    {
      "note": "#1 keyword_in_title 20 + price_below_min -10 = 10",
      "title": "Поставка металлоконструкций",
      "description": "без подробностей",
      "price": "500000.00", "region": "Тверь", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_score": 10, "expected_level": "low"
    },
    {
      "note": "#2 КМД main в названии (ILIKE+substring) → keyword_in_title 20 + region 10; price NULL нейтрально; main 'металлоконструкции' в описании не добавляет (правило 2 сработало по title) = 30",
      "title": "Разработка КМД и КМ",
      "description": "металлоконструкции каркаса",
      "price": null, "region": "Москва", "law_type": "fz223",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_score": 30, "expected_level": "low"
    },
    {
      "note": "#3 негатив 'бу' в названии при exclude_on_negative_in_title → матч НЕ создаётся",
      "title": "Металлоконструкции бу со склада",
      "description": "распродажа",
      "price": "2000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_match": false
    },
    {
      "note": "#4 keyword_in_title 20 + price 20 + region 10 + source_closed -30 = 20",
      "title": "Монтаж металлоконструкций",
      "description": "работы по объекту",
      "price": "3000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "completed",
      "expected_score": 20, "expected_level": "low"
    },
    {
      "note": "#5 жёсткий фильтр §7.1: регион Чечня в excluded_regions → не матчится",
      "title": "Металлоконструкции для ремонта",
      "description": "поставка",
      "price": "2000000.00", "region": "Чечня", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_match": false
    },
    {
      "note": "#6 жёсткий фильтр §7.1: law=other при непустом law_types → не матчится",
      "title": "Металлоконструкции прочие",
      "description": "поставка",
      "price": "2000000.00", "region": "Москва", "law_type": "other",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_match": false
    },
    {
      "note": "#7 keyword_in_title 20 + high_priority_bonus 10 ('срочно' в названии) + price 20 + region 10 + okpd 5 + deadline_far 10 = 75",
      "title": "Срочно требуются металлоконструкции",
      "description": "монтаж на площадке",
      "price": "2000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": ["25.11.10"], "deadline_offset_days": 10, "source_status": "active",
      "expected_score": 75, "expected_level": "medium"
    },
    {
      "note": "#8 keyword_in_title 20 + price 20 + region 10 + deadline_soon -20 (дедлайн +1 день) = 30",
      "title": "Поставка металлоконструкций",
      "description": "комплект",
      "price": "2000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 1, "source_status": "active",
      "expected_score": 30, "expected_level": "low"
    },
    {
      "note": "#9 негатив 'бывшие в употреблении' в ОПИСАНИИ (exclude только по названию) → keyword_in_title 20 + negative -20 + price 20 + region 10 = 30",
      "title": "Поставка металлоконструкций",
      "description": "оборудование бывшие в употреблении",
      "price": "2000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_score": 30, "expected_level": "low"
    },
    {
      "note": "#10 low_priority-only ('доставка' морфологически в названии, нет main/exact/broad) → low_priority_score 2 + price 20 + region 10 = 32",
      "title": "Услуги доставки грузов",
      "description": "логистика",
      "price": "2000000.00", "region": "Москва", "law_type": "fz44",
      "okpd2": [], "deadline_offset_days": 3, "source_status": "active",
      "expected_score": 32, "expected_level": "low"
    }
  ]
}
```

> Примечание исполнителю: эталонные `expected_score` пересчитаны вручную под финальную логику `Scorer`; при прогоне сверить и, если расхождение — диагностировать (баг кода vs опечатка в фикстуре), а не подгонять слепо. Истинная слепая зона предфильтра (exact-фраза только в описании) проверяется отдельным тестом в `CandidateQueryTest`, а не golden-набором — поэтому здесь её нет. Аббревиатуры (`КМД`) ловятся FTS-веткой и в названии, и в описании (search_vector включает description), поэтому «КМД только в описании» НЕ слепая зона. Расширение до ~50 реальных закупок ЕИС — выход стадии A (после A6).

- [ ] **Step 4: Golden-тест**

Create `tests/Feature/Scoring/GoldenSetTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Enums\LawType;
use App\Enums\RunTrigger;
use App\Enums\TenderSourceStatus;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-06-13 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('воспроизводит эталонные баллы golden-набора', function () {
    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/golden/profile_metal.json')), true);

    $company = Company::factory()->create();
    $source = Source::factory()->create();
    Source::factory()->create(['code' => 'manual']);
    CompanySource::withoutTenancy()->create(['company_id' => $company->id, 'source_id' => $source->id, 'is_active' => true]);

    $p = $data['profile'];
    $profile = SearchProfile::factory()->for($company)->create([
        'is_active' => true,
        'name' => $p['name'],
        'min_price' => $p['min_price'],
        'max_price' => $p['max_price'],
        'regions' => $p['regions'],
        'excluded_regions' => $p['excluded_regions'],
        'okpd2_codes' => $p['okpd2_codes'],
        'law_types' => $p['law_types'],
        'exclude_on_negative_in_title' => $p['exclude_on_negative_in_title'],
    ]);
    foreach ($p['keywords'] as $kw) {
        Keyword::factory()->for($profile)->create(['value' => $kw['value'], 'type' => KeywordType::from($kw['type'])]);
    }

    $tenders = [];
    foreach ($data['cases'] as $i => $case) {
        $tenders[$i] = Tender::factory()->create([
            'source_id' => $source->id,
            'title' => $case['title'],
            'description' => $case['description'],
            'price' => $case['price'],
            'region' => $case['region'],
            'law_type' => LawType::from($case['law_type']),
            'okpd2' => $case['okpd2'],
            'source_status' => TenderSourceStatus::from($case['source_status']),
            'deadline_at' => now()->addDays($case['deadline_offset_days']),
            'owner_company_id' => null,
        ]);
    }

    app(MatchProfileService::class)->run($profile->refresh(), RunTrigger::Manual);

    $failures = [];
    foreach ($data['cases'] as $i => $case) {
        $match = TenderMatch::withoutTenancy()->where('tender_id', $tenders[$i]->id)->first();
        $expectMatch = $case['expected_match'] ?? true;

        if (! $expectMatch) {
            if ($match !== null) {
                $failures[] = "Кейс #{$i} ({$case['note']}): ожидался НЕ-матч, получен score={$match->score}";
            }

            continue;
        }

        if ($match === null) {
            $failures[] = "Кейс #{$i} ({$case['note']}): ожидался матч score={$case['expected_score']}, матча нет";

            continue;
        }
        if ($match->score !== $case['expected_score']) {
            $failures[] = "Кейс #{$i} ({$case['note']}): ожидался score={$case['expected_score']}, получен {$match->score}";
        }
    }

    expect($failures)->toBe([]);
});
```

- [ ] **Step 5: Запустить golden-тест, выверить фикстуру**

Run: `./vendor/bin/pest tests/Feature/Scoring/GoldenSetTest.php`
Expected: PASS. Если расхождения — проверить, баг в Scorer/Matcher или некорректное ожидание; поправить причину (а не подгонять слепо).

- [ ] **Step 6: Pint + PHPStan + Commit**

```bash
./vendor/bin/pint tests/Feature/Scoring && ./vendor/bin/phpstan analyse
git add tests/Fixtures/golden tests/Feature/Scoring/GoldenSetTest.php database/migrations 2>/dev/null
git commit -m "A4: EXPLAIN кандидатного запроса + golden-набор (правила §7.2, слепая зона trigram)"
```

---

## Задача 11: Финальная верификация ветки

- [ ] **Step 1: Прогнать миграции на чистой тест-БД (если добавлен индекс)**

Run: `php artisan migrate:fresh --env=testing` (или штатный механизм RefreshDatabase отработает в тестах).

- [ ] **Step 2: Полный прогон**

Run: `composer test`
Expected: pint OK, phpstan L6 `[OK] No errors`, все Pest-тесты зелёные (≈115 прежних + новые A4).

- [ ] **Step 3: Зафиксировать число тестов/assertions** для записи в роадмап.

---

## Self-Review (прогон против спеки)

**1. Покрытие §7:**
- §7.1 жёсткие фильтры → `HardFilter` (Задача 4): law_type ✓, excluded_regions ✓, price-обе-границы ✓, source ✓, manual-owner ✓; regions НЕ жёсткий ✓.
- §4.2 кандидатный фильтр дедлайна `(deadline_at IS NULL OR deadline_at >= now() + deadline_min_days)` → `CandidateQuery` (НЕ §7.1, НЕ скоринг) ✓ — см. решение №10, тесты в `CandidateQueryTest`.
- §7.2 правила → `Scorer` (Задача 3): exact title/desc ✓, main/broad title/desc ✓, high_priority +10 ✓, low_priority +2 при отсутствии main/exact/broad ✓, negative −20 + exclude_on_negative_in_title ✓, price ±/нейтрально+no_price ✓, region +10 ✓, deadline far/soon/no_deadline ✓, okpd префикс ✓, source_status closed −30 ✓, unknown=active ✓. «Слишком общий предмет/ручная проверка» (Р12) — не реализуются ✓ (не в коде).
- §7.3 partial-override → `ScoringWeights` (Задача 1) ✓; пороги уровней через jsonb НЕ переопределяются ✓ (только в `RelevanceLevel::fromScore`).
- §7.4 clamp [0,100] + уровни «≥» → Scorer + `RelevanceLevel` ✓.
- §7.5 risk_flags (deadline_soon/no_price/no_deadline/source_closed) + recommendation из уровня ✓.
- §7.6 предфильтр FTS+trigram, exact в PHP → `CandidateQuery` + `KeywordMatchResolver` ✓; ILIKE-ветка нормализует ё/регистр (решение 3a) ✓; истинная слепая зона (exact-фраза только в описании) → отдельный тест в `CandidateQueryTest` ✓ (аббревиатуры в описании НЕ слепы — их ловит FTS).
- §13: Scorer таблица кейсов ✓, golden-набор ✓ (11 кейсов с вручную пересчитанными баллами; расширение до ~50 на живых ЕИС — выход стадии A, A6), импорт→автоматчинг ✓, изоляция в джобе ✓.

**2. Обязательные заметки роадмапа (A4):**
- composite-индекс по EXPLAIN → Задача 10 ✓ (с явным решением «не требуется», если GIN достаточно).
- trigram только на title — слепая зона (exact-фраза только в описании) → проверена тестом в `CandidateQueryTest` ✓. Важно: аббревиатуры (КМД и пр.) НЕ слепая зона — стеммер их сохраняет, FTS-ветка ловит в т.ч. в описании (решения 2/3 уточнены).
- decimal:2 → string, сравнения через bccomp → `Scorer`/`HardFilter` ✓ (+ ext-bcmath, Задача 3 шаг 0).
- raw insert в company_tenders обязан передавать first_matched_at → `recomputeBest` ставит при создании, не трогает при апдейте ✓.
- пересчёт best_* явным запросом по company_id, без `CompanyTender::matches()`/TenantContext → ✓; advisory-lock против гонки 23505 параллельных профилей ✓.

**3. Согласованность типов/сигнатур:**
- `Scorer::score(Tender, SearchProfile, ScoringWeights, list<KeywordHit> $positive, list<KeywordHit> $negative): ScoreResult` — едина в Задаче 3 и вызове из `MatchProfileService` (Задача 7) ✓.
- `KeywordMatchResolver::resolve(Tender, Collection<Keyword>): array{positive, negative}` — Задача 6 и вызов в Задаче 7 ✓.
- `CandidateQuery::build(SearchProfile, Collection<Keyword>, list<int>, ?CarbonInterface): Builder<Tender>` — Задача 5 и вызов в Задаче 7 ✓.
- `HardFilter::passes(Tender, SearchProfile, list<int>): bool` — Задача 4 и вызов в Задаче 7 ✓.
- `ScoringWeights::for(SearchProfile): self`, `get(string): int` — Задача 1, используется в Задачах 3/7 ✓.
- `MatchProfileService::run(SearchProfile, RunTrigger, ?CarbonInterface): RadarRun` — Задача 7, вызов в Задачах 8/9/10 ✓.
- `MatchProfileJob(int $searchProfileId, string $trigger, ?string $sinceUpdatedAt)` — Задача 8, диспатч в Задаче 9 ✓.
- `KeywordHit(value, KeywordType, ?field)` / `ScoreResult(...)` — Задача 2, потребляются в Задачах 3/7 ✓.

**4. §4.2 пайплайн матчинга:**
- Кандидаты (SQL): owner-scope + source + текстовый предфильтр §7.6 + дедлайн-фильтр → `CandidateQuery` ✓.
- `Scorer` → upsert `tender_matches`; при первом матче — `company_tenders`(status=new, first_matched_at=now); после каждого upsert пересчёт `best_score = MAX(score)`/`best_level` по паре → `MatchProfileService::persist/recomputeBest` ✓ (advisory-lock, withoutTenancy).
- N+1 устранён: `enabledSourceIds` вычисляется один раз и пробрасывается в чанк-замыкание ✓.
- Инкрементальность по `updated_at` — параметр `sinceUpdatedAt` есть, но в A4 всегда `null` (полный рескан); вывод из прошлого `RadarRun` — этап B/планировщик (решение №11, явно отложено) ✓.
- `notification_threshold`→`NotifyJob`/`notified_count` — вне A4 (Telegram = A7); `notified_count` остаётся 0 ✓ (отмечено).

**5. Плейсхолдеры:** не обнаружено — каждый шаг содержит исполнимый код/команду.
