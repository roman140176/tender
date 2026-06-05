# Спецификация: «Тендерный радар» — SaaS мониторинга закупок

**Дата:** 2026-06-05 (v2 — после adversarial-ревью по 4 линзам)
**Статус:** ожидает финального ревью владельца
**Основание:** ТЗ в корне проекта (`/home/roman/sites/www/tender/TЗ`) + анализ и ревью от 2026-06-05

---

## 1. Цель и рамки

Коммерческий мультитенантный SaaS: автоматический сбор закупок (44-ФЗ, 223-ФЗ, коммерческие), нормализация, дедупликация, скоринг релевантности под профили компаний-клиентов, уведомления и рабочий кабинет менеджера.

Продукт **не** подаёт заявки, не даёт юридических заключений, не обходит защиты площадок (ТЗ, разделы 1, 12.2, 24).

## 2. Ключевые решения (отступления от ТЗ и причины)

| # | Решение | Отступление от ТЗ | Причина |
|---|---------|-------------------|---------|
| Р1 | Коммерческий SaaS, мультитенантность с первого дня | ТЗ неявно | Подтверждено владельцем 2026-06-05 |
| Р2 | Стек Laravel 13 / PHP 8.3, Inertia + Vue 3, PostgreSQL 16, Redis (predis) | ТЗ рекомендует Node/NestJS/Next | Экспертиза и инфраструктура владельца; нативный SOAP/XMLReader для ЕИС; стек ТЗ — рекомендация, не требование (16.1). Laravel 13 — актуальный мажор на момент старта (июнь 2026) |
| Р3 | 3 легальных адаптера вместо 8 парсеров: ЕИС + платный агрегатор (этап B+) + ручной импорт | ТЗ требует 8 источников первой очереди | У 5 ЭТП нет публичных API; парсинг упирается в антибот-защиты, обход которых ТЗ запрещает (7.4, 24); контент покрывается ЕИС и агрегаторами. Критерий 27.6 допускает «адаптерную архитектуру» |
| Р4 | Сбор глобальный, матчинг per-company | В ТЗ не определено | ЕИС ~10–15 тыс. извещений/день: качаем один раз для всех, иначе N×M-взрыв нагрузки и стоимости AI |
| Р5 | Глобальный `tenders` + per-company проекция `company_tenders` | Модель 19.6 ТЗ кладёт per-company поля на глобальный Tender | Иначе компании затирают данные друг друга; релевантность живёт только в `tender_matches` |
| Р6 | «Направление» (5.3) и «поисковый профиль» (8.2/19.3) — одна сущность `search_profiles` | В ТЗ две пересекающиеся концепции | Наборы полей идентичны; слияние снимает противоречия о порогах и группировках. В UI — «Направление» |
| Р7 | Два раздельных статуса: `tenders.source_status` (площадка) и `company_tenders.status` (воркфлоу) | В 19.6 одно поле `status` | 8.1 п.14 (3 значения площадки) и 13.4 (12 значений воркфлоу) — разные сущности |
| Р8 | AI: опциональный слой, вызывается только после детерминированного порога, резюме кэшируется на глобальной закупке; per-company вызовы тарифицируются и лимитируются | ТЗ не специфицирует экономику | Иначе LLM-вызов на каждую закупку × компанию делает тарифы убыточными |
| Р9 | Биллинг = тарифы с лимитами, управляемые суперадмином вручную; платёжный шлюз вне scope | Раздел 25 без платёжной логики | Фискализация/эквайринг — отдельный контур; для запуска достаточно ручного управления подписками |
| Р10 | Хостинг в РФ | В ТЗ не указано | 152-ФЗ: персональные данные контактных лиц закупок и пользователей |
| Р11 | `tenders` **не партиционируется** на старте | — | UNIQUE-констрейнты дедупа (`source_id+external_id`, hash) несовместимы с ключом партиции в PostgreSQL; PG 16 штатно держит миллионы строк с btree+GIN. Партиционирование/архивация — операционное решение после релиза по факту деградации |
| Р12 | Правила скоринга ТЗ «−20 слишком общий предмет» и «−15 требует ручной проверки документов» детерминированно не реализуются | Опущены 2 из 14 правил 11.1 | Нет алгоритмического критерия; покрываются AI-слоем этапа B (риски в `ai_assessment`) |
| Р13 | Ручной импорт приватен компании-загрузчику | В ТЗ не определено | Иначе закупки, загруженные компанией А, утекали бы в матчинг компании Б через глобальный пул |
| Р14 | Риски и рекомендация — детерминированные с этапа A (`risk_flags`, `action_recommendation` из правил скоринга), AI этапа B дополняет | ТЗ подразумевает только AI-вывод (12.3) | 9.1/13.3/14.1 ТЗ требуют риски/рекомендацию в карточке и Telegram уже на этапе A, когда AI выключен |
| Р15 | Межисточниковая дедупликация (правило 5 ТЗ §10: title+customer+date+price) не реализуется в первом релизе | Опущено правило 5 (и «номер закупки» как отдельный уровень — покрыт `external_id`/`number` в рамках источника) | Высокий риск ложной склейки; станет актуальным только при подключении агрегатора (этап B+) — решение принять тогда |

## 3. Стек

- **Backend:** PHP 8.3, Laravel 13, Horizon (очереди на Redis, клиент predis), Scheduler (как диспетчер, см. §14-планировщик).
- **Frontend:** Inertia.js + Vue 3 + Tailwind CSS (кабинет и админка — одно приложение, роли разводят доступ).
- **БД:** PostgreSQL 16; FTS `russian` + расширение `pg_trgm` (точные фразы/аббревиатуры). Meilisearch — отложен (ТЗ 16.1 относит во «вторую версию»).
- **Файлы:** локальный диск (dev) / S3-совместимое хранилище в РФ (prod) через Laravel Filesystem; временная область на диске для распаковки архивов ЕИС.
- **Экспорт:** `maatwebsite/excel` (XLSX/CSV, **чтение импорта — только chunk-режимом**), `barryvdh/laravel-dompdf` (PDF), Blade-шаблоны (Markdown), встроенный JSON.
- **Инфраструктура:** Docker + docker-compose (app, horizon, scheduler, postgres, redis, nginx), GitHub Actions (pint + phpstan + pest), бэкапы `pg_dump` ежедневно, хранение 14 дней.

## 4. Архитектура

### 4.1. Компоненты

```
┌─ Кабинет (Inertia/Vue) ─┐   ┌─ Админка суперадмина ─┐
└───────────┬─────────────┘   └──────────┬────────────┘
            │        Laravel app (HTTP)  │
            └──────────────┬─────────────┘
   ┌───────────────────────┼───────────────────────────┐
   │ Сервисы домена        │                           │
   │  Sources/Adapters → Normalizer → Deduplicator     │
   │  Matcher → Scorer → Notifier → Reporter           │
   │  AiAnalyzer (опц.)                                │
   └───────────────────────┼───────────────────────────┘
              Horizon (Redis queues) + Scheduler
                           │
                     PostgreSQL 16
```

### 4.2. Пайплайн данных

Два независимых процесса (Р4):

**Сбор (глобальный, per источник):**
1. `CollectSourceJob(source)` — оркестратор; по расписанию источника или вручную. Повторный запуск при активном `source_collect_runs` для источника блокируется (advisory lock).
2. Оркестратор порождает дочерние джобы: `DownloadPackageJob` (один архив/диапазон дат) → `ProcessChunkJob` (~500 извещений). Агрегация итогов — Laravel Job Batches → `source_collect_runs`. Очередь `collect` со своими воркерами; `timeout` джоба > длительности чанка, `retry_after` очереди > `timeout`, воркеры с `--max-jobs`/`--memory`.
3. `Normalizer` приводит запись к `NormalizedTender` (единая структура, раздел 9 ТЗ).
4. `Deduplicator` ищет дубль каскадом: `(source_id, external_id)` → `source_url` → `content_hash` (см. §6.5). Найден — обновляет изменившиеся **содержательные** поля (price, deadline_at, source_status, title, description, attachments), пишет diff в `tender_changes` (служебные `last_seen_at`/`updated_at` не трекаются), обновляет `last_seen_at`. Не найден — создаёт `tenders`.

**Матчинг (per поисковый профиль):**
1. `MatchProfileJob(searchProfileId, companyId)` — `company_id` передаётся **явным параметром** (в воркере нет auth-контекста); запускается по расписанию, вручную или после импорта (`RadarRun.trigger ∈ {manual, schedule, import}`).
2. Кандидаты (SQL): закупки, новые/изменённые после прошлого `RadarRun` профиля, с фильтрами: жёсткие фильтры §7.1 + `(deadline_at IS NULL OR deadline_at >= now() + deadline_min_days)` + текстовый предфильтр §7.6 + `(owner_company_id IS NULL OR owner_company_id = :companyId)` (Р13).
3. `Scorer` (§7) → upsert `tender_matches`; при первом матче пары создаётся `company_tenders` (status=new, first_matched_at=now). **После каждого upsert** пересчитываются `company_tenders.best_score = MAX(score)` и `best_level` по всем матчам пары (company_id, tender_id).
4. Балл ≥ `notification_threshold` профиля → `NotifyJob` (антидубль — §9).
5. Итог — в `radar_runs`.

Инкрементальность: сбор ЕИС — по датам публикации/изменения пакетов (дельта от `last_collected_at`); матчинг — по `tenders.updated_at` > отметки прошлого `RadarRun` профиля.

## 5. Модель данных

Per-company таблицы имеют `company_id`. Тенант-изоляция: Eloquent-scope по компании в HTTP-контексте; **в queued-джобах scope не работает** (нет auth) — `company_id` передаётся параметром джоба и применяется явно. Глобальная `tenders` не имеет scope — **закупки компании читаются только через join с `company_tenders`/`tender_matches`**; прямые выборки из `tenders` разрешены только в сборе и админских сервисах. Глобальные таблицы: `tenders`, `sources`, `tender_changes`, `tender_attachments`, `source_collect_runs`, `ai_summaries`.

### Справочники и доступ

- **companies**: id, name, inn, website, description, industries `jsonb`, products `jsonb`, services `jsonb`, contacts `jsonb` (контактные лица — ТЗ 5.1), notification_email, telegram_chat_id, crm_webhook_url `nullable`, is_active, timestamps.
- **users**: id, company_id `nullable` (NULL = суперадмин), name, email `unique`, password, role `enum(superadmin, admin, manager, viewer)`, is_active, timestamps.
- **plans**: id, code `unique`, name, max_search_profiles, max_sources, max_users, max_ai_calls_per_month `nullable`, allow_schedules, allow_ai, allow_api, is_active, timestamps. Сидируются три тарифа из раздела 25 ТЗ.
- **subscriptions**: id, company_id, plan_id, status `enum(trial, active, expired)`, started_at, expires_at `nullable`, timestamps. Лимиты проверяются при создании профилей/пользователей и включении расписаний/AI.

### Поиск

- **search_profiles** («Направление» в UI, Р6): id, company_id, name, is_active, law_types `jsonb` (`[]` = любой закон), regions `jsonb` (приоритетные регионы — бонус +10, **не** жёсткий фильтр), excluded_regions `jsonb` (жёсткий фильтр), okpd2_codes `jsonb` (целевые коды для правила +5; `[]` = правило неактивно), min_price `nullable`, max_price `nullable` (NULL = фильтр отключён), deadline_min_days `default 0`, notification_threshold `default 60`, exclude_on_negative_in_title `bool default false`, scoring_weights `jsonb nullable` (схема — §7.3), schedule_type `enum(manual, daily, weekly, cron)`, schedule_cron `nullable`, timezone `default 'Europe/Moscow'`, last_run_at, next_run_at, timestamps.
  Валидация: профиль нельзя **активировать** без ≥1 позитивного ключа (main/exact/broad).
- **keywords**: id, search_profile_id, value, type `enum(main, exact, broad, negative, high_priority, low_priority)`, origin `enum(manual, ai, dictionary)` `default manual`, timestamps. Per-keyword веса **нет** (вес определяется типом и `scoring_weights` профиля; поле `weight` из ТЗ 19.4 намеренно опущено).
- **synonyms** (этап B): id, company_id `nullable` (NULL = глобальный словарь), term, variants `jsonb`, timestamps.

### Источники

- **sources**: id, code `unique` (`eis`, `manual`, `saby`, `kontur`, …), name, base_url, connection_type `enum(api, open_data, manual, parsing)`, adapter_class, is_active, default_config `jsonb`, schedule_cron `nullable`, last_collected_at, timestamps.
- **company_sources**: id, company_id, source_id, is_active, config `text encrypted nullable` (клиентские API-ключи), timestamps, `unique(company_id, source_id)`. Определяет источники, участвующие в матчинге компании (лимит `max_sources`). При первом ручном импорте строка для `source=manual` создаётся автоматически. **Инвариант: зашифрованные поля не участвуют в WHERE/JOIN/INDEX** (рандомизированный IV).

### Закупки

- **tenders** (глобальная, **без партиционирования** — Р11): id, source_id, external_id, number `nullable`, source_url, title, description `text nullable`, customer_name `nullable`, customer_inn `nullable`, region `nullable`, price `decimal nullable`, currency `default 'RUB'`, law_type `enum(fz44, fz223, commercial, other)`, procedure_type `nullable`, source_status `enum(active, completed, cancelled, unknown)` (`unknown` трактуется везде как `active`), published_at `nullable`, deadline_at `nullable`, okpd2 `jsonb`, ktru `jsonb`, extra `jsonb`, content_hash, owner_company_id `nullable` (NOT NULL для `source=manual` — Р13), search_vector `tsvector` (см. ниже), first_seen_at, last_seen_at, timestamps. (Поля источника nullable осознанно: ручной импорт и агрегаторы не гарантируют полноту — заказчик/регион/дата публикации могут отсутствовать, нормализатор не падает.)
  Индексы: `unique(source_id, external_id)`, btree(source_url), btree(content_hash), published_at, deadline_at, **updated_at** (ключ инкрементального матчинга §4.2 — горячий путь), region, price, law_type, owner_company_id, GIN(search_vector), **GIN trigram(title)** (`pg_trgm`, для exact-фраз/аббревиатур). Composite-индексы под фактический паттерн матчера — по EXPLAIN на наполненной таблице (этап A4).
  `search_vector` — generated-колонка через **raw SQL миграцию** (schema builder её не умеет), колонка `guarded` в модели:
  ```sql
  search_vector tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('russian'::regconfig, coalesce(title, '')), 'A') ||
    setweight(to_tsvector('russian'::regconfig, coalesce(description, '')), 'B')
  ) STORED
  ```
  (`::regconfig` и `coalesce` обязательны: IMMUTABLE-требование generated-колонок и NULL-устойчивость; setweight A/B — различение названия и описания в предфильтре.)
- **tender_changes**: id, tender_id, source_collect_run_id `nullable`, field, old_value `text`, new_value `text`, created_at. Только содержательные поля (§4.2 шаг 4).
- **tender_attachments**: id, tender_id, title, url, file_type, local_path `nullable`, parsed_text `text nullable` (этап B+), created_at. Скачивание — только где разрешено правилами источника; по умолчанию храним только URL.

### Работа компании с закупкой

- **company_tenders**: id, company_id, tender_id, status `enum(new, interesting, review_docs, handed_over, in_progress, declined, irrelevant, archived, participating, not_participating, won, lost)`, assigned_user_id `nullable`, best_score, best_level `enum(high, medium, weak, low)`, first_matched_at, timestamps, `unique(company_id, tender_id)`.
- **tender_matches**: id, company_id, tender_id, search_profile_id, radar_run_id, score, level, reason `jsonb` (структурированный: `[{rule, points, detail}]`; строка для UI/Telegram рендерится презентером), matched_keywords `jsonb` (`[{value, type, field}]`), matched_negative_keywords `jsonb`, risk_flags `jsonb` (детерминированные — §7.5), action_recommendation `enum(hand_over, review_docs, monitor, decline)` (§7.5), ai_assessment `jsonb nullable` (этап B, схема — §8), timestamps, `unique(search_profile_id, tender_id)`.
- **tender_comments**: id, company_id, tender_id, user_id, comment `text`, created_at.
- **tender_status_history**: id, company_id, tender_id, user_id `nullable` (NULL = система), old_status, new_status, created_at.

### Запуски, уведомления, AI, аудит

- **source_collect_runs**: id, source_id, trigger `enum(manual, schedule, import)`, status `enum(running, ok, error)`, started_at, finished_at, fetched_count, new_count, updated_count, error_count, log `text`.
- **radar_runs**: id, company_id, search_profile_id, trigger `enum(manual, schedule, import)`, status, started_at, finished_at, processed_count, matched_count, notified_count, error_count, log `text`.
- **notification_logs**: id, company_id, tender_id `nullable`, search_profile_id `nullable`, channel `enum(telegram, email, web, webhook)`, recipient, status `enum(queued, sent, failed)`, payload `jsonb`, sent_at, error `nullable`.
- **ai_summaries** (этап B, глобальный кэш — Р8): id, tender_id `unique`, provider, model, summary `text`, requirements `jsonb`, risks `jsonb`, tokens_used, created_at. Кэш-вызов **не тарифицируется** на компанию.
- **ai_usage** (этап B): id, company_id, period (`YYYY-MM`), calls, tokens_used, timestamps, `unique(company_id, period)`. Учёт per-company AI-вызовов; энфорсит `plans.max_ai_calls_per_month`.
- **audit_logs** (этап B): id, company_id `nullable`, user_id, action, entity_type, entity_id, payload `jsonb`, ip, created_at.

## 6. Источники и адаптеры

### 6.1. Интерфейс адаптера

```php
interface TenderSourceAdapter
{
    public function sourceCode(): string;
    /** Потоково отдаёт сырые записи; $params содержит дельту (since) */
    public function collect(CollectParams $params): iterable; // yield RawTender
    public function normalize(RawTender $raw): NormalizedTender;
    /** Опционально: точечная дозагрузка деталей/вложений */
    public function details(string $externalId): ?RawTenderDetails;
}
```

Регистрация — `sources.adapter_class` + service container. Ошибки изолируются: исключение одного источника не валит общий сбор; N подряд неудачных запусков → `sources.is_active = false` + алерт суперадмину (7.4 ТЗ).

### 6.2. Адаптер ЕИС (этап A)

- **Основной канал — массовые выгрузки**: ежесуточные ZIP-архивы открытых данных подсистемы интеграции ЕИС (пакеты по регионам/типам документов, внутри — XML). Скачивание во временную область на диск, потоковая распаковка, обход XML через `XMLReader` (гигабайтные файлы в память не загружаются). SOAP-сервис СОИ (`getDocsIP`) — **только точечно** для `details()`: он документо-ориентирован и троттлится, для полного дневного слива не предназначен.
- Доступ: бесплатная регистрация потребителя машиночитаемых данных через Госуслуги (`zakupki.gov.ru/pmd`). Конкретные хосты/эндпоинты интеграции **фиксируются на этапе A после получения доступа** (они менялись), в спеке не хардкодятся.
- Извещения 44-ФЗ и 223-ФЗ; маппинг полей по схемам ЕИС (НМЦК → price, ОКПД2/КТРУ, регион заказчика); неизвестные поля — в `extra`. Реестры контрактов 44-ФЗ, договоров 223-ФЗ, каталог КТРУ и перечень ЭТП (упомянуты в 6.1.1 ТЗ) — **вне scope первого релиза**: нужны для анализа победителей (раздел 29 ТЗ); поля победителя/контракта в `extra` заполняются, только если придут из источника.
- Дельта — по датам пакетов; первичный бэкафилл — 30 дней (конфигурируемо).
- Риск: схемы ЕИС меняются версиями — маппинг-слой + мониторинг ошибок парсинга.

### 6.3. Ручной импорт (этап A)

Форматы: CSV, XLSX (чтение **только чанками** — иначе OOM), JSON, список URL (22.1 ТЗ). Поток: загрузка через UI → валидация (лимит размера, MIME, типы колонок) → `Normalizer`/`Deduplicator`, `source = manual`, `owner_company_id = компания-загрузчик` (Р13), фиксируется как `source_collect_runs (trigger=import)`.

После успешного импорта **автоматически** диспатчатся `MatchProfileJob` для всех активных профилей компании-загрузчика (`radar_runs.trigger = import`). `company_sources(manual)` создаётся при первом импорте. `external_id` для manual генерируется детерминированно: `sha256(owner_company_id + канонизированные title|customer_name|published_at|price)` — повторный импорт той же строки опирается на `unique(source_id, external_id)` и не создаёт дубль.

Приватность: закупки с `owner_company_id` видны и матчатся **только** компании-владельцу (фильтр в §4.2 шаг 2 и во всех выборках UI).

### 6.4. Платный агрегатор (этап B+, при наличии договора/ключа)

Saby/СБИС Торги или Контур.Закупки — официальные платные API (~15–50 тыс ₽/год), легально агрегируют 700+ площадок (включая Росэлторг, Сбер-АСТ, РТС, B2B-Center, Фабрикант). Ключ — глобальный или клиентский (`company_sources.config`). Дневные лимиты API — в конфиге адаптера. При подключении агрегатора принять решение по межисточниковой дедупликации (Р15).

Прямой парсинг площадок не реализуется (Р3). Источники второй очереди (6.2 ТЗ) — только ручной импорт либо будущие адаптеры.

### 6.5. Дедупликация и content_hash

- Каскад: `(source_id, external_id)` → `source_url` → `content_hash`; выигрывает первый сработавший уровень (запись обновляется, не создаётся вторая).
- `content_hash = sha256` канонизированных полей: `title | customer_name | customer_inn | published_at::date | price`. Канонизация: lower, trim, схлопывание пробелов, стабильная сериализация (детерминированность — иначе ложные diff в `tender_changes`).
- Правила ТЗ §10 «номер закупки» и «title+customer+date+price между источниками» намеренно не реализуются (Р15).

## 7. Скоринг

Детерминированный, для пары (закупка × профиль).

### 7.1. Жёсткие фильтры (до баллов)

Непрошедшие не скорятся: `law_type ∈ профиля` (пустой `law_types` = любой закон; `other` проходит только при пустом); `region ∉ excluded_regions`; `price` в границах `min_price..max_price`, **если** заданы обе стороны соответствующих границ и price NOT NULL (NULL-цена жёстким фильтром не отсеивается); источник ∈ включённых у компании; для manual-закупок — владелец (Р13). `regions` профиля жёстким фильтром **не** является (только бонус).

### 7.2. Правила начисления баллов

**Каждое правило срабатывает максимум один раз** на пару (закупка × профиль) — кратность совпадений баллы не умножает. Дефолтные веса — `config/scoring.php`, переопределение — `search_profiles.scoring_weights` (§7.3).

Текстовые правила (приоритет и взаимоисключение):
1. `exact`-фраза найдена в названии → **+30**; правила 2–3 для неё не начисляются. Если exact только в описании → **+15**.
2. `main`/`broad`-ключ в названии → **+20**; иначе ключ в описании → **+15** (берётся максимум по полю, не сумма).
3. `high_priority`-ключ найден (любое поле) → дополнительно **+10** (поверх правил 1–2, если они сработали по другим ключам; сам по себе +20/+15 не даёт).
4. `low_priority`-ключ найден и **нет** совпадений типов main/exact/broad → **+2** (правила 1–2 к нему не применяются).
5. Минус-слово (`negative`) найдено в названии или описании → **−20** (однократно). Если `exclude_on_negative_in_title = true` и минус-слово в **названии** → закупка исключается из матчинга полностью (матч не создаётся).

Прочие правила:
- price ≥ min_price → **+20**; price < min_price → **−10**; `price IS NULL` или `min_price IS NULL` → нейтрально (+ риск-флаг `no_price` при NULL-цене).
- region ∈ `regions` профиля → **+10**.
- deadline_at − now() > 5 дней → **+10**; < 2 дней → **−20** (+ флаг `deadline_soon`); `deadline_at IS NULL` → нейтрально (+ флаг `no_deadline`).
- ОКПД2/КТРУ закупки имеет **префиксное** совпадение с кодом из `okpd2_codes` → **+5**; пустой список = правило неактивно.
- `source_status ∈ {completed, cancelled}` → **−30** (+ флаг `source_closed`); `unknown` = как `active`.
- Правила ТЗ «слишком общий предмет −20» и «требует ручной проверки −15» — не реализуются детерминированно (Р12).

### 7.3. Схема `scoring_weights` (partial-override поверх дефолтов)

```json
{
  "exact_in_title": 30, "exact_in_description": 15,
  "keyword_in_title": 20, "keyword_in_description": 15,
  "high_priority_bonus": 10, "low_priority_score": 2,
  "negative_keyword": -20,
  "price_above_min": 20, "price_below_min": -10,
  "priority_region": 10,
  "deadline_far_bonus": 10, "deadline_soon_penalty": -20,
  "deadline_far_days": 5, "deadline_soon_days": 2,
  "okpd_match": 5, "source_closed": -30
}
```

Частичный объект **мержится** поверх дефолтов. Пороги уровней (80/60/40) через jsonb не переопределяются.

### 7.4. Итог и уровни

Сумма обрезается в [0, 100]. Уровни — полуинтервалы, **все сравнения «≥»**: [80, 100] = high, [60, 80) = medium, [40, 60) = weak, [0, 40) = low. То же правило «≥» — для `notification_threshold` и AI-порога.

### 7.5. Риски и рекомендация (детерминированные, этап A — Р14)

- `risk_flags`: `deadline_soon`, `no_price`, `no_deadline`, `source_closed` — выводятся из правил §7.2. AI этапа B добавляет содержательные риски в `ai_assessment`.
- `action_recommendation` из уровня: high → `hand_over` («передать менеджеру»), medium → `review_docs` («посмотреть ТЗ»), weak → `monitor`, low → `decline`. Соответствует словарю ТЗ 26.1.
- Обе величины присутствуют в карточке и Telegram-формате **с этапа A** (требование 9.1/13.3/14.1 ТЗ).

### 7.6. Текстовый предфильтр кандидатов и морфология

- FTS-ветка: лексемы main/broad/high_priority/low_priority-ключей через `to_tsquery('russian')` против `search_vector` — морфология (склонения) покрывается стеммером.
- **Trigram/ILIKE-ветка**: `exact`-фразы и короткие аббревиатуры (≤4 символов: КМД, НМЦК…) **не должны зависеть от FTS** — стеммер выкидывает короткие токены и стоп-слова, что даёт ложноотрицательные. Для них кандидатный SQL включает OR-ветку по GIN trigram(title)/ILIKE.
- Точная проверка фраз — нормализованный поиск в PHP по выбранным кандидатам.
- Словарь синонимов (этап B) расширяет FTS-запрос. Качество — golden-набор (§13).

## 8. AI-модуль (этап B, опциональный)

- Абстракция `AiProvider` (`analyzeTender()`, `expandKeywords()`): драйверы OpenAI / Anthropic / OpenAI-совместимый (локальная модель, прокси) / null. Включение — флаг тарифа + флаг компании.
- Вызывается **только** для закупок с детерминированным баллом ≥ AI-порога (default 60, сравнение «≥»).
- Глобальное резюме/требования/риски — кэш `ai_summaries` (один вызов на закупку, не тарифицируется). Per-company оценка (промпт 26.1 ТЗ) — `tender_matches.ai_assessment`, тарифицируется в `ai_usage`, лимит — `plans.max_ai_calls_per_month`.
- Строгая JSON-схема ответа per-company оценки (валидируется, при невалидном ответе — retry/discard):
  ```json
  {
    "isRelevant": true,
    "aiLevel": "high|medium|low",
    "summary": "...", "reason": "...",
    "risks": ["..."],
    "recommendation": "hand_over|review_docs|monitor|decline"
  }
  ```
  `aiLevel` — **отдельная** шкала AI (3 значения), на балльный `level` (4 значения) не влияет и отображается рядом; `recommendation` использует тот же enum, что §7.5. AI не меняет детерминированный балл.
- `ai_summaries.requirements`/`risks` — массивы строк.
- Отложено «по спросу» (после B): черновик письма менеджеру, AI-группировка по направлениям (12.1 ТЗ).

## 9. Уведомления

- **Telegram** (этап A): бот сервиса; chat_id компании, валидация тестовым сообщением. Формат — **полный по 14.1 ТЗ**: название, сумма, регион, срок подачи, источник, релевантность, почему подходит (reason), **риски** (risk_flags / AI), **рекомендация** (action_recommendation), ссылка. Inline-кнопки (этап B) → статусы: «Интересно» → `interesting`, «Отклонить» → `declined`, «Посмотреть позже» → `review_docs`, «Передать менеджеру» → `handed_over`.
- **Email** (этап B): SMTP, дайджест по итогам запуска, опциональное вложение XLSX.
- **Web** (этап B): колокольчик (database notifications), логируется в `notification_logs` с `channel=web`.
- **CRM webhook** (после B): POST JSON; SSRF-защита — только http(s), запрет приватных подсетей, без редиректов.
- **Антидубль**: уведомление по паре (профиль × закупка) — один раз (проверка по `notification_logs`). Повторное — только по явным триггерам: цена изменилась на ≥10%; `deadline_at` изменился; `source_status` стал терминальным (completed/cancelled); score пересёк `notification_threshold` снизу вверх.

## 10. UI (Inertia + Vue)

Кабинет: **Дашборд** (счётчики новых/релевантных, **блок высокоприоритетных закупок**, ближайшие дедлайны, последние запуски, ошибки источников, **график динамики найденных закупок** — все 7 элементов 13.1 ТЗ), **Закупки** (фильтры и колонки по 13.2, статусы инлайн, ответственный), **Карточка** (по 13.3: данные, балл с расшифровкой reason, риски, рекомендация, AI-блок, комментарии, история, кнопки статусов, ссылка на оригинал), **Направления** (CRUD + ключи 6 типов + расписание + порог), **Источники**, **Импорт**, **Отчёты** (этап B), **Настройки**.

**Отчёты** (этап B) — обязательный состав по 13.7 ТЗ: обработано / новых / релевантных / отклонено; топ закупок; ближайшие дедлайны; **группировки по направлениям, регионам, источникам**; список рекомендаций. Периоды: день/неделя/месяц/произвольный. Форматы: веб-страница, email, экспорт XLSX/CSV/PDF/**Markdown/JSON** (22.2 ТЗ).

Админка суперадмина (этап B): компании и подписки, тарифы, глобальные источники и логи, AI-настройки, статистика сервиса, логи запусков.

## 11. Роли и права

| Действие | superadmin | admin | manager | viewer |
|---|---|---|---|---|
| Управление сервисом (компании, тарифы, источники, AI) | ✔ | — | — | — |
| Настройки компании, профили, ключи, пользователи | ✔ | ✔ | — | — |
| Статусы, комментарии, ответственный | ✔ | ✔ | ✔ | — |
| Просмотр закупок и отчётов, экспорт | ✔ | ✔ | ✔ | ✔ |

Laravel Policies + middleware. Тенант-изоляция: scope в HTTP; в джобах — явный `company_id` (§5); доступ к глобальной `tenders` — только через проекции; «unique в рамках компании» — всегда составной `unique(company_id, …)`.

## 12. Безопасность

Пароли — bcrypt; секреты источников — `encrypted` cast (инвариант: не участвуют в WHERE/JOIN/INDEX; ротация `APP_KEY` = artisan-команда ре-шифрования; потеря ключа = потеря клиентских ключей источников — бэкап `APP_KEY` обязателен); CSRF/XSS — штатно Laravel + Vue; rate limiting на auth/API; импорт: лимит размера, MIME, chunk-чтение, экранирование `=`-формул при экспорте XLSX/CSV; SSRF-защита webhook (§9); `audit_logs` (этап B); бэкап БД ежедневно. ПДн (152-ФЗ): хостинг РФ, политика конфиденциальности и удаление данных компании по запросу — до коммерческого запуска.

## 13. Тестирование

- **Unit (Pest):** Normalizer (фикстуры реальных XML ЕИС), Deduplicator (каскад, канонизация hash, manual-`external_id`), Scorer (таблица кейсов на каждое правило §7.2, NULL-кейсы, clamp, границы уровней «≥»), маппинг кнопок/статусов/рекомендаций.
- **Golden-набор:** ~50 реальных закупок с эталонной разметкой для 2–3 профилей; регрессия качества матчинга (в т.ч. аббревиатуры/exact через trigram-ветку).
- **Feature:** роли/изоляция тенантов **в HTTP и в джобах** (джоб компании А не видит данные Б — обязательный тест), приватность manual-импорта, CRUD, импорт→автоматчинг, статусы, уведомления (fake-каналы), антидубль.
- CI: pint + phpstan + pest.

## 14. Этапы реализации

**Этап A — walking skeleton (~6–9 недель):**
1. Каркас: Laravel + Inertia + docker-compose, auth, одна компания, роли admin/manager.
2. Миграции — сразу финальная схема §5 (без партиций — Р11).
3. Направления + ключи + CRUD + валидация.
4. Ручной импорт (chunk) → Normalizer → Deduplicator → автоматчинг (trigger=import).
5. Scorer (§7 целиком, включая risk_flags/recommendation) + tender_matches + company_tenders.
6. Список закупок + карточка + статусы + комментарии.
7. Адаптер ЕИС (архивы ZIP→XML, оркестратор/чанк-джобы) + ручной запуск.
8. Telegram-уведомления (полный формат 14.1).
9. Просмотр логов запусков.
**Выход A:** проверка качества скоринга на живых данных ЕИС с реальным профилем (golden-набор).

**Этап B — релиз (итого ~14–20 недель):**
10. Мультитенантность: регистрация компаний, суперадмин, подписки/тарифы с лимитами.
11. Планировщик: Scheduler = **минутный диспетчер** (`WHERE is_active AND next_run_at <= now()` → dispatch в очередь → пересчёт `next_run_at` через cron-expression с учётом timezone; джиттер 0–300 с при массовых совпадениях). Никаких per-profile `$schedule->call()`.
12. Email + web-уведомления + inline-кнопки Telegram.
13. Дашборд (7 элементов), отчёты (состав §10) + экспорт XLSX/CSV/PDF/Markdown/JSON.
14. AI-модуль (провайдер, кэш, ai_usage, лимиты).
15. Словарь синонимов + расширение ключей.
16. Админка суперадмина, audit_logs, мониторинг «тишины» источников (источник без результатов N запусков = алерт, а не норма).
17. Архивная политика: tender_changes, notification_logs, audit_logs.
18. Документация: администратора, пользователя, «как добавить источник», деплой.

**После релиза (по спросу):** адаптер Saby/Контур (+ решение Р15), CRM-интеграции, Google Sheets, анализ документов (parsed_text), реестры контрактов/КТРУ, источники второй очереди, раздел 29 ТЗ, партиционирование по факту деградации.

## 15. Вне scope первого релиза

Прямой парсинг ЭТП; платёжный шлюз/фискализация; CRM-интеграции; Google Sheets; Meilisearch; semantic search; анализ вложений; публичный API; реестры контрактов 44-ФЗ / договоров 223-ФЗ / каталог КТРУ; черновики писем и AI-группировка; партиционирование БД.

## 16. Соответствие критериям готовности (раздел 27 ТЗ)

Критерии 1–5, 7, 9–15, 19, 20 — этап A. Критерии 6 (адаптерная архитектура + живой ЕИС), 8, 16, 17, 18, 21–25 — этап B. Все 25 закрываются к концу этапа B; «риски/рекомендация» в карточке и Telegram (13.3/14.1) присутствуют с этапа A в детерминированном виде (Р14).

## 17. Допущения и риски

- **Доступ к выгрузкам ЕИС** — регистрация через Госуслуги на этапе A; до получения — публичные opendata-наборы; точные эндпоинты фиксируются по факту доступа.
- **Схемы ЕИС меняются** — маппинг-слой + мониторинг ошибок; постоянная операционная нагрузка.
- **Объёмы:** ~10–15 тыс. извещений/день → миллионы строк/год; PG 16 без партиций держит это с btree+GIN (Р11); контроль — размер БД и латентность матчинга в мониторинге.
- **AI из РФ:** абстракция допускает OpenAI-совместимые локальные/прокси-эндпоинты.
- **Платный агрегатор** — нужен договор (~20–50 тыс ₽/год); до этого коммерческие торги — ручным импортом.
