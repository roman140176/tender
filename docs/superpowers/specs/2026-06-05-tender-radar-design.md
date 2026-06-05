# Спецификация: «Тендерный радар» — SaaS мониторинга закупок

**Дата:** 2026-06-05
**Статус:** дизайн одобрен, ожидает ревью спеки
**Основание:** ТЗ в корне проекта (`/home/roman/sites/www/tender/TЗ`) + анализ от 2026-06-05

---

## 1. Цель и рамки

Коммерческий мультитенантный SaaS: автоматический сбор закупок (44-ФЗ, 223-ФЗ, коммерческие), нормализация, дедупликация, скоринг релевантности под профили компаний-клиентов, уведомления и рабочий кабинет менеджера.

Продукт **не** подаёт заявки, не даёт юридических заключений, не обходит защиты площадок (ТЗ, разделы 1, 12.2, 24).

## 2. Ключевые решения (отступления от ТЗ и причины)

| # | Решение | Отступление от ТЗ | Причина |
|---|---------|-------------------|---------|
| Р1 | Коммерческий SaaS, мультитенантность с первого дня | ТЗ неявно | Подтверждено владельцем 2026-06-05 |
| Р2 | Стек Laravel 12 / PHP 8.3, Inertia + Vue 3, PostgreSQL 16, Redis | ТЗ рекомендует Node/NestJS/Next | Экспертиза и инфраструктура владельца; нативный SOAP/XMLReader для ЕИС; стек ТЗ — рекомендация, не требование (16.1) |
| Р3 | 3 легальных адаптера вместо 8 парсеров: ЕИС (открытые данные/СОИ) + платный агрегатор (Saby или Контур, этап B+) + ручной импорт | ТЗ требует 8 источников первой очереди | У Росэлторг/Сбер-АСТ/РТС/B2B-Center/Фабрикант нет публичных API; парсинг упирается в антибот-защиты, обход которых ТЗ запрещает (7.4, 24); их контент покрывается ЕИС (госзакупки) и агрегаторами (коммерческие). Критерий 27.6 допускает «адаптерную архитектуру» вместо живых адаптеров |
| Р4 | Сбор глобальный, матчинг per-company | В ТЗ не определено | ЕИС публикует ~10–15 тыс. извещений/день: качаем один раз для всех, иначе N×M-взрыв нагрузки и стоимости AI |
| Р5 | Глобальный `tenders` + per-company проекция `company_tenders` | Модель 19.6 ТЗ кладёт per-company поля на глобальный Tender | Иначе компании затирают данные друг друга; релевантность живёт только в `tender_matches` |
| Р6 | «Направление» (5.3) и «поисковый профиль» (8.2/19.3) — одна сущность `search_profiles` | В ТЗ две пересекающиеся концепции | Наборы полей идентичны; слияние снимает противоречия 11.3/13.2/19 о порогах и группировках. В UI называется «Направление» |
| Р7 | Два раздельных статуса: `tenders.source_status` (площадка) и `company_tenders.status` (воркфлоу) | В 19.6 одно поле `status` | Смешаны разные сущности: 8.1 п.14 (3 значения площадки) vs 13.4 (12 значений воркфлоу) |
| Р8 | AI: строго опциональный слой, вызывается только после детерминированного порога, резюме кэшируется на глобальной закупке | ТЗ не специфицирует экономику | Иначе LLM-вызов на каждую закупку × компанию делает тарифы убыточными |
| Р9 | Биллинг = тарифы с лимитами, управляемые суперадмином вручную; платёжный шлюз вне scope | Раздел 25 без платёжной логики | Фискализация/эквайринг — отдельный контур; для запуска достаточно ручного управления подписками |
| Р10 | Хостинг в РФ | В ТЗ не указано | 152-ФЗ: персональные данные контактных лиц закупок и пользователей |

## 3. Стек

- **Backend:** PHP 8.3, Laravel 12, Horizon (очереди на Redis), Scheduler.
- **Frontend:** Inertia.js + Vue 3 + Tailwind CSS (кабинет и админка — одно приложение, роли разводят доступ).
- **БД:** PostgreSQL 16; полнотекстовый поиск — встроенный FTS, конфигурация `russian`, tsvector-колонки. Meilisearch — отложен (ТЗ 16.1 сам относит во «вторую версию»).
- **Файлы:** локальный диск (dev) / S3-совместимое хранилище в РФ (prod) через Laravel Filesystem.
- **Экспорт/импорт:** `maatwebsite/excel` (XLSX/CSV), `barryvdh/laravel-dompdf` (PDF).
- **Инфраструктура:** Docker + docker-compose (app, horizon, scheduler, postgres, redis, nginx), GitHub Actions (lint + tests), бэкапы `pg_dump` по cron.

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

Два независимых процесса (решение Р4):

**Сбор (глобальный, per источник):**
1. `CollectSourceJob(source)` — по расписанию источника или вручную.
2. Адаптер потоково отдаёт сырые записи (`RawTender`).
3. `Normalizer` приводит к `NormalizedTender` (единая структура, раздел 9 ТЗ).
4. `Deduplicator` ищет дубль: `(source_id, external_id)` → `source_url` → `content_hash`. Найден — обновляет изменившиеся поля и пишет diff в `tender_changes`, обновляет `last_seen_at`. Не найден — создаёт `tenders`.
5. Итог запуска — в `source_collect_runs`.

**Матчинг (per поисковый профиль):**
1. `MatchProfileJob(searchProfile)` — по расписанию профиля или вручную (`RadarRun`).
2. Кандидаты: закупки, новые/изменённые с прошлого запуска профиля, отфильтрованные SQL-ом (law_type, регионы, цена, `deadline_at >= now() + deadline_min_days`, источник ∈ включённых у компании) + FTS-предфильтр по ключам.
3. `Scorer` считает балл по правилам (раздел 7 спеки) → пишет/обновляет `tender_matches`, создаёт `company_tenders` (status=new) при первом матче.
4. Балл ≥ порога профиля → `NotifyJob` (Telegram/email).
5. Итог — в `radar_runs`.

Инкрементальность: сбор ЕИС идёт по датам публикации/изменения (дельта от `last_collected_at`); матчинг — по `tenders.updated_at >` отметки прошлого `RadarRun` профиля.

## 5. Модель данных

Все per-company таблицы имеют `company_id` и глобальный Eloquent-scope по текущей компании. `tenders`, `sources`, `tender_changes`, `tender_attachments`, `source_collect_runs`, `ai_summaries` — глобальные.

### Справочники и доступ

- **companies**: id, name, inn, website, description, industries `jsonb`, products `jsonb`, services `jsonb`, notification_email, telegram_chat_id, crm_webhook_url `nullable`, is_active, timestamps.
- **users**: id, company_id `nullable` (NULL = суперадмин), name, email `unique`, password, role `enum(superadmin, admin, manager, viewer)`, is_active, timestamps. Права — Laravel Policies по матрице раздела 4 ТЗ.
- **plans**: id, code `unique`, name, max_search_profiles, max_sources, max_users, allow_schedules, allow_ai, allow_api, is_active, timestamps. Сидируются три тарифа из раздела 25 ТЗ.
- **subscriptions**: id, company_id, plan_id, status `enum(trial, active, expired)`, started_at, expires_at `nullable`, timestamps. Лимиты тарифа проверяются при создании профилей/пользователей и включении расписаний/AI.

### Поиск

- **search_profiles** («Направление» в UI, решение Р6): id, company_id, name, is_active, law_types `jsonb`, regions `jsonb`, excluded_regions `jsonb`, min_price `nullable`, max_price `nullable`, deadline_min_days `default 0`, notification_threshold `default 60`, scoring_weights `jsonb nullable` (переопределение дефолтов), schedule_type `enum(manual, daily, weekly, cron)`, schedule_cron `nullable`, timezone `default 'Europe/Moscow'`, last_run_at, next_run_at, timestamps.
- **keywords**: id, search_profile_id, value, type `enum(main, exact, broad, negative, high_priority, low_priority)` (6 типов из 5.2 ТЗ), origin `enum(manual, ai, dictionary)` `default manual`, timestamps.
- **synonyms** (этап B): id, company_id `nullable` (NULL = глобальный словарь), term, variants `jsonb`, timestamps.

### Источники

- **sources** (глобальный реестр адаптеров): id, code `unique` (`eis`, `manual`, `saby`, `kontur`, …), name, base_url, connection_type `enum(api, open_data, manual, parsing)`, adapter_class, is_active, default_config `jsonb`, schedule_cron `nullable` (расписание глобального сбора), last_collected_at, timestamps.
- **company_sources**: id, company_id, source_id, is_active, config `text encrypted nullable` (клиентские API-ключи, п.6.1.7 ТЗ), timestamps, `unique(company_id, source_id)`. Определяет, какие источники участвуют в матчинге компании (и лимит тарифа `max_sources`).

### Закупки

- **tenders** (глобальная): id, source_id, external_id, number `nullable` (номер закупки), source_url, title, description `text`, customer_name, customer_inn `nullable`, region, price `decimal nullable`, currency `default 'RUB'`, law_type `enum(fz44, fz223, commercial, other)`, procedure_type `nullable`, source_status `enum(active, completed, cancelled, unknown)`, published_at, deadline_at `nullable`, okpd2 `jsonb`, ktru `jsonb`, extra `jsonb` (адрес поставки, контакты, лоты, победитель/контракт — поля 9.1 ТЗ без отдельной логики), content_hash, search_vector `tsvector` (generated, russian, по title+description), first_seen_at, last_seen_at, timestamps.
  Индексы: `unique(source_id, external_id)`, source_url, content_hash, published_at, deadline_at, region, price, law_type, GIN(search_vector).
- **tender_changes**: id, tender_id, source_collect_run_id `nullable`, field, old_value `text`, new_value `text`, created_at. История изменений данных закупки (требование раздела 10 ТЗ).
- **tender_attachments**: id, tender_id, title, url, file_type, local_path `nullable`, parsed_text `text nullable` (этап B+), created_at. Скачивание — только где разрешено правилами источника (раздел 24 ТЗ); по умолчанию храним только URL.

### Работа компании с закупкой

- **company_tenders**: id, company_id, tender_id, status `enum(new, interesting, review_docs, handed_over, in_progress, declined, irrelevant, archived, participating, not_participating, won, lost)` (12 статусов из 13.4 ТЗ), assigned_user_id `nullable`, best_score, best_level `enum(high, medium, weak, low)`, first_matched_at, timestamps, `unique(company_id, tender_id)`.
- **tender_matches**: id, company_id, tender_id, search_profile_id, radar_run_id, score, level, reason `text` (объяснение балла), matched_keywords `jsonb`, matched_negative_keywords `jsonb`, ai_assessment `jsonb nullable` (этап B), timestamps, `unique(search_profile_id, tender_id)`. Источник истины по релевантности (решение Р5); `company_tenders.best_*` — денормализованный максимум по профилям.
- **tender_comments**: id, company_id, tender_id, user_id, comment `text`, created_at.
- **tender_status_history**: id, company_id, tender_id, user_id `nullable` (NULL = система), old_status, new_status, created_at.

### Запуски, уведомления, аудит

- **source_collect_runs** (глобальный сбор): id, source_id, trigger `enum(manual, schedule)`, status `enum(running, ok, error)`, started_at, finished_at, fetched_count, new_count, updated_count, error_count, log `text`.
- **radar_runs** (матчинг профиля): id, company_id, search_profile_id, trigger `enum(manual, schedule)`, status, started_at, finished_at, processed_count, matched_count, notified_count, error_count, log `text`.
- **notification_logs**: id, company_id, tender_id `nullable`, search_profile_id `nullable`, channel `enum(telegram, email, webhook)`, recipient, status `enum(queued, sent, failed)`, payload `jsonb`, sent_at, error `nullable`.
- **ai_summaries** (этап B, кэш per глобальная закупка — решение Р8): id, tender_id `unique`, provider, model, summary `text`, requirements `jsonb`, risks `jsonb`, tokens_used, created_at.
- **audit_logs** (этап B): id, company_id `nullable`, user_id, action, entity_type, entity_id, payload `jsonb`, ip, created_at.

## 6. Источники и адаптеры

### 6.1. Интерфейс адаптера (аналог раздела 18 ТЗ)

```php
interface TenderSourceAdapter
{
    public function sourceCode(): string;

    /** Потоково отдаёт сырые записи; $since — дельта-сбор */
    public function collect(CollectParams $params): iterable; // yield RawTender

    public function normalize(RawTender $raw): NormalizedTender;

    /** Опционально: дозагрузка деталей/вложений */
    public function details(string $externalId): ?RawTenderDetails;
}
```

Регистрация адаптеров — через `sources.adapter_class` + service container. Новый источник = новый класс + строка в реестре, ядро не меняется. Ошибки адаптера изолируются: исключение в одном источнике не валит общий сбор; N подряд неудачных запусков → `sources.is_active = false` + алерт суперадмину (требование 7.4 ТЗ об автоотключении).

### 6.2. Адаптер ЕИС (этап A)

- Канал: официальные открытые данные zakupki.gov.ru — выгрузки opendata + СОИ (SOAP, `int44.zakupki.gov.ru/eis-integration/services/getDocsIP`). Токен — бесплатная регистрация потребителя машиночитаемых данных через Госуслуги (`zakupki.gov.ru/pmd`). Это штатный официальный доступ, не обход авторизации.
- Парсинг XML — потоковый `XMLReader` (файлы до гигабайт). Извещения 44-ФЗ и 223-ФЗ; маппинг полей по схемам ЕИС (НМЦК → price, ОКПД2/КТРУ, регион заказчика).
- Дельта: по датам публикации/изменения документов; глубина первичного бэкафилла — 30 дней (конфигурируемо).
- Риск: схемы ЕИС меняются версиями — парсер пишется через маппинг-слой, неизвестные поля складываются в `extra`.

### 6.3. Ручной импорт (этап A)

Форматы: CSV, XLSX, JSON, список URL (раздел 22.1 ТЗ). Загрузка через UI → валидация (лимит размера, типы колонок, экранирование формул) → те же `Normalizer`/`Deduplicator`, source = `manual`. Шаблон файла скачивается из UI. Импортированные записи привязываются к компании-загрузчику через автоматический матчинг её профилей.

### 6.4. Платный агрегатор (этап B+, при наличии договора/ключа)

Saby/СБИС Торги или Контур.Закупки — официальные платные API (~15–50 тыс ₽/год), легально агрегируют 700+ площадок, включая Росэлторг, Сбер-АСТ, РТС, B2B-Center, Фабрикант. Один адаптер закрывает коммерческий сегмент. Ключ — глобальный (сервиса) или клиентский (`company_sources.config`). Дневные лимиты API учитываются в конфиге адаптера.

Прямой парсинг площадок не реализуется (решение Р3). Источники второй очереди (6.2 ТЗ) — только через ручной импорт либо будущие адаптеры.

## 7. Скоринг

Детерминированный, выполняется в `Scorer` для пары (закупка × профиль):

1. **Жёсткие фильтры** (до баллов): law_type ∈ профиля; регион ∉ excluded_regions; источник включён у компании. Непрошедшие не скорятся.
2. **Баллы** — правила раздела 11.1 ТЗ, веса берутся из `search_profiles.scoring_weights` (jsonb) с дефолтами в `config/scoring.php`:
   точная фраза в названии +30; ключ в названии +20; ключ в описании +15; сумма ≥ min_price +20; приоритетный регион +10; срок подачи > 5 дней +10; совпадение ОКПД2/КТРУ +5; high_priority-ключ +10; low_priority-ключ +2 (вместо стандартного веса); сумма < min_price −10; дедлайн < 2 дней −20; минус-слово −20; source_status = completed/cancelled −30.
3. **Результат** обрезается в [0, 100]. Минус-слово в названии — опционально полное исключение (флаг профиля), по умолчанию только штраф.
4. **Уровни** (11.2 ТЗ): ≥80 high, 60–79 medium, 40–59 weak, <40 low.
5. **Объяснимость**: каждое сработавшее правило пишется в `tender_matches.reason` («+20 ключ "металлоконструкции" в названии; −20 минус-слово "аренда"») и `matched_keywords`.

**Русская морфология:** кандидаты отбираются PostgreSQL FTS (`russian`-конфигурация, стемминг склонений); точные фразы (тип `exact`) проверяются нормализованным подстрочным поиском в PHP; аббревиатуры (КМД, НМЦК) матчятся как точные токены. Словарь синонимов (этап B) расширяет запрос на этапе кандидатов. Качество матчинга контролируется golden-набором закупок в тестах (раздел 13 спеки).

## 8. AI-модуль (этап B, опциональный)

- Абстракция `AiProvider` (contract: `analyzeTender()`, `expandKeywords()`): драйверы OpenAI / Anthropic / OpenAI-совместимый (локальная модель) / null. Конфиг — у суперадмина, включение — флаг тарифа + флаг компании.
- Вызывается **только** для закупок с детерминированным баллом ≥ AI-порога (default 60) — пре-фильтрация стоимости (решение Р8).
- Резюме/требования/риски — кэш `ai_summaries` на глобальной закупке (один вызов на закупку, не на компанию). Per-company оценка релевантности (промпт 26.1 ТЗ с контекстом компании) — пишется в `tender_matches.ai_assessment`, формат ответа — JSON из 26.2 ТЗ.
- AI не меняет детерминированный балл — его вывод показывается рядом (рекомендация/риски) и используется в уведомлениях. Конфликтов «кто главнее» нет by design.
- Лимит вызовов на компанию/месяц — поле тарифа; расход токенов логируется (`ai_summaries.tokens_used`).

## 9. Уведомления

- **Telegram** (этап A): один бот сервиса; компания указывает chat_id (личка/группа), валидация — тестовое сообщение при сохранении. Формат — по 14.1 ТЗ (название, сумма, регион, дедлайн, источник, балл, причина, ссылка). Inline-кнопки (этап B): «Интересно» → `interesting`, «Отклонить» → `declined`, «Посмотреть позже» → `review_docs`, «Передать менеджеру» → `handed_over` (маппинг кнопок 15.1 на статусы 13.4 — зафиксирован здесь).
- **Email** (этап B): SMTP, дайджест по итогам запуска (таблица закупок + ссылки), опциональное вложение XLSX.
- **Web** (этап B): колокольчик в кабинете (Laravel database notifications).
- **CRM webhook** (после B): POST JSON карточки; SSRF-защита — только http(s), запрет приватных подсетей, без редиректов.
- Антидубль: уведомление по паре (профиль × закупка) отправляется один раз; повторно — только при существенном изменении (цена, дедлайн) — проверка по `notification_logs`.

## 10. UI (Inertia + Vue)

Кабинет компании: **Дашборд** (новые/релевантные за период, ближайшие дедлайны, последние запуски, ошибки источников — раздел 13.1 ТЗ), **Закупки** (таблица: фильтры и колонки по 13.2, статусы инлайн, назначение ответственного), **Карточка закупки** (поля по 13.3: данные, балл с объяснением, AI-блок, комментарии, история, кнопки статусов, ссылка на оригинал), **Направления** (CRUD профилей + ключи 6 типов + расписание + порог), **Источники** (вкл/выкл, свой ключ), **Импорт** (загрузка файлов, история), **Отчёты** (этап B), **Настройки** (компания, уведомления, пользователи).

Админка суперадмина (этап B): компании и подписки, тарифы, глобальные источники и их логи, AI-настройки, статистика сервиса, логи запусков (раздел 21 ТЗ).

## 11. Роли и права

| Действие | superadmin | admin | manager | viewer |
|---|---|---|---|---|
| Управление сервисом (компании, тарифы, источники, AI) | ✔ | — | — | — |
| Настройки компании, профили, ключи, пользователи | ✔ | ✔ | — | — |
| Статусы, комментарии, ответственный | ✔ | ✔ | ✔ | — |
| Просмотр закупок и отчётов, экспорт | ✔ | ✔ | ✔ | ✔ |

Реализация — Laravel Policies + middleware; изоляция тенантов — глобальный scope `company_id` на всех per-company моделях (обход — только явный `withoutTenancy()` в админских сервисах).

## 12. Безопасность

Пароли — bcrypt (Laravel); секреты источников — `encrypted` casts (ключ приложения вне репозитория, `.env`); CSRF/XSS — штатные механизмы Laravel + escaping Vue; rate limiting на auth и API; валидация импортов (размер, MIME, экранирование `=`-формул при экспорте в XLSX/CSV); SSRF-защита webhook (раздел 9); аудит действий (`audit_logs`, этап B); бэкап БД — `pg_dump` ежедневно + хранение 14 дней. ПДн (152-ФЗ): хостинг РФ, политика конфиденциальности и удаление данных компании по запросу — организационно до коммерческого запуска.

## 13. Тестирование

- **Unit (Pest):** Normalizer (фикстуры реальных XML ЕИС), Deduplicator (все правила + обновление diff), Scorer (таблица кейсов на каждое правило + границы 0/100), маппинг кнопок/статусов.
- **Golden-набор:** ~50 реальных закупок с эталонной разметкой релевантности для 2–3 тестовых профилей; регрессия качества матчинга при изменении правил/морфологии.
- **Feature:** авторизация/роли/изоляция тенантов (компания А не видит данные Б — обязательный тест), CRUD профилей, импорт, смена статусов, уведомления (fake-каналы).
- CI: GitHub Actions — pint + phpstan + pest на каждый push.

## 14. Этапы реализации

**Этап A — walking skeleton (~6–9 недель):**
1. Каркас: Laravel + Inertia + docker-compose, auth, одна компания, роли admin/manager.
2. Модель данных целиком (миграции — сразу финальная схема, чтобы не мигрировать B болезненно).
3. Направления + ключи (6 типов) + CRUD.
4. Ручной импорт CSV/XLSX/JSON → Normalizer → Deduplicator.
5. Scorer + tender_matches + company_tenders.
6. Список закупок + карточка + статусы + комментарии.
7. Адаптер ЕИС (opendata/СОИ) + ручной запуск сбора и матчинга.
8. Telegram-уведомления.
9. Логи запусков (просмотр).
**Выход A:** на реальных данных ЕИС проверяем качество скоринга с реальным профилем.

**Этап B — релиз (итого ~14–20 недель):**
10. Полная мультитенантность: регистрация компаний, суперадмин, изоляция, подписки/тарифы с лимитами.
11. Планировщик (расписания профилей и источников).
12. Email-уведомления + web + inline-кнопки Telegram.
13. Дашборд, отчёты (день/неделя/месяц/период) + экспорт XLSX/CSV/PDF.
14. AI-модуль (провайдер-абстракция, резюме-кэш, per-company оценка).
15. Словарь синонимов + расширение ключей.
16. Админка суперадмина, audit_logs, мониторинг «тишины» источников.
17. Документация: администратора, пользователя, «как добавить источник», деплой.

**После релиза (по спросу):** адаптер Saby/Контур, CRM-интеграции (15.3 ТЗ), Google Sheets, анализ документов (parsed_text), источники второй очереди, раздел 29 ТЗ.

## 15. Вне scope первого релиза

Прямой парсинг ЭТП; платёжный шлюз/фискализация; CRM-интеграции; Google Sheets; Meilisearch/Elasticsearch; semantic search; анализ вложений-документов; публичный API для клиентов; мобильные приложения.

## 16. Соответствие критериям готовности (раздел 27 ТЗ)

Критерии 1–5, 7, 9–15, 19, 20 — этап A. Критерии 6 (адаптерная архитектура + ЕИС), 8, 16, 17, 18, 21–25 — этап B. Все 25 закрываются к концу этапа B.

## 17. Допущения и риски

- **Доступ к СОИ ЕИС** требует регистрации через Госуслуги — оформляется на этапе A; до получения токена адаптер работает на публичных opendata-выгрузках.
- **Схемы ЕИС меняются** — маппинг-слой + мониторинг ошибок парсинга; это постоянная операционная нагрузка, не разовая работа.
- **Объёмы:** ~10–15 тыс. извещений/день → миллионы строк `tenders` в год; партиционирование по `published_at` закладывается в миграции с порога (партиции по месяцам), архивная политика — решение этапа B.
- **AI из РФ:** доступность OpenAI/Anthropic не гарантирована — абстракция допускает OpenAI-совместимые локальные/прокси-эндпоинты.
- **Платный агрегатор** — нужен договор и бюджет (~20–50 тыс ₽/год); до этого коммерческие торги покрываются ручным импортом.
