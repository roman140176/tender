# A7 — Telegram-уведомления + ручной запуск радара + дашборд (дизайн)

**Статус:** дизайн одобрен 2026-06-18, готов к `writing-plans`.
**Addendum к мастер-спеке** `docs/superpowers/specs/2026-06-05-tender-radar-design.md` (далее «спека») — A7 уточняет и сужает её §6 (уведомления), §9 (антидубль), §13.1 (дашборд), §14.1 (формат Telegram) до объёма этапа A7 роадмапа `docs/superpowers/plans/2026-06-05-stage-a-roadmap.md`.

## 1. Скоуп A7

A7 закрывает строку роадмапа: **Telegram-уведомления (полный формат §14.1), антидубль, ручной запуск радара из UI, дашборд** (с графиком динамики). Канал — только Telegram, формат полный, риски/рекомендация **детерминированные** (Р14 спеки; AI — этап B).

**Уже готово в схеме/коде (A1–A6) — не создаём, потребляем:**
- `companies.telegram_chat_id` (спека §5.1/строка 89); `search_profiles.notification_threshold` (default 60); `radar_runs.notified_count`; таблица `notification_logs` (company_id, tender_id, search_profile_id, channel `enum(telegram,email,web,webhook)`, recipient, status `enum(queued,sent,failed)`, payload `jsonb`, sent_at, error) + модель `NotificationLog` + енумы `NotificationChannel`/`NotificationStatus`.
- `tender_matches` (score, level, reason `jsonb`, matched_keywords, risk_flags `jsonb`, action_recommendation `enum`) — продукт Scorer (A4); презентеры `MatchReasonPresenter` + лейблы рисков/рекомендаций (A5).
- `MatchProfileService` / `MatchProfileJob` (A4) — создают/обновляют `tender_matches` и `company_tenders`. `RunTrigger::Manual` (енум). `RadarRun` (tenant-scoped).

## 2. Компоненты (каждый — одна ответственность, тестируется изолированно)

**2.1 `TelegramGateway` (интерфейс)** — `sendMessage(string $chatId, string $text, array $opts = []): void`. Транспорт за интерфейсом (паттерн `EisGateway` из A6).
- Боевая реализация `HttpTelegramGateway` — Telegram Bot API `POST https://api.telegram.org/bot{token}/sendMessage` через Http-фасад; `$response->throw()` (чтобы джоб-ретраи работали); токен из `config('services.telegram.bot_token')`.
- `FakeTelegramGateway` (в тестах) — пишет отправленные сообщения в память для ассертов. Биндинг в `AppServiceProvider`.
- Токен/чувствительное НЕ логируется в исключения.

**2.2 `NotificationDecider` (сервис антидубля, §9)** — решает, слать ли уведомление по паре (профиль × закупка).
- Берёт последнюю запись `notification_logs` со `status=sent` для (search_profile_id, tender_id, channel=telegram).
- Нет записи → **слать**.
- Есть запись → слать **только** при одном из ре-триггеров (спека §9/строка 274): цена (`tenders.price`) изменилась на **≥10%** относительно payload прошлого уведомления; изменился `deadline_at`; `source_status` стал терминальным (`completed`/`cancelled`); текущий балл пересёк `notification_threshold` **снизу вверх** (прошлый балл < порога, текущий ≥). Иначе — **не слать**.
- Для сравнения payload прошлого уведомления хранит снимок: price, deadline_at, source_status, score (см. 2.3).

**2.3 `NotifyJob` (queued, очередь `notifications`)** — ядро.
- Вход: `tenderMatchId` (скаляр; сериализуемость, как джобы A6).
- Загружает `tender_match` (+ профиль, закупку, компанию `withoutTenancy` — глобальный диспетчер). Если у компании нет `telegram_chat_id` → лог `notification_logs(status=failed, error='no chat_id')` и выход.
- `NotificationDecider` решает — если нет → выход без отправки (без лога-дубля).
- Рендер текста презентером (2.4) → `TelegramGateway::sendMessage` → лог `notification_logs(channel=telegram, recipient=chat_id, status=sent, payload={price,deadline_at,source_status,score,...}, sent_at)`; при исключении транспорта → `status=failed, error=...` (джоб ретраится). Инкремент `radar_runs.notified_count` атомарно.
- `tries=3`, backoff; `after_commit` (чтобы видеть зафиксированный match).

**2.4 `TelegramNotificationPresenter`** — рендер формата **§14.1** (спека §6/строка 270): название, сумма, регион, срок подачи, источник, релевантность (level), почему подходит (reason — через `MatchReasonPresenter`), **риски** (risk_flags, детерминированные), **рекомендация** (action_recommendation), ссылка на оригинал. Telegram-разметка (HTML/MarkdownV2 — выбрать в плане, экранировать спецсимволы). Inline-кнопки — **этап B, НЕ A7**.

**2.5 Подключение в матчинг** — в `MatchProfileService` после создания/обновления `tender_match`: если `score ≥ profile.notification_threshold` → `NotifyJob::dispatch($match->id)`. Очередь `notifications` (отдельно от `collect`, чтобы не конкурировать). Решение о ре-нотификации — внутри `NotifyJob` (через `NotificationDecider`), не в сервисе.

**2.6 Ручной запуск радара (UI)** — кнопка «Запустить радар»:
- per-направление (на странице Направлений) и по всей компании;
- → `MatchProfileJob::dispatch($profileId, RunTrigger::Manual->value, null)` (для всех активных профилей при company-wide);
- гейт `manage-company`; редирект с flash. **Per-profile cron-расписание (`schedule_type` daily/weekly/cron) — вне A7** (роадмап = «ручной запуск»; плановый матчинг уже идёт через collect→fanout из A6).

**2.7 Настройки компании (UI)** — в `CompanySettingsController`/форме: поле `telegram_chat_id` + кнопка «Отправить тестовое сообщение» (валидация канала, спека §6/строка 270) → `TelegramGateway::sendMessage(chat_id, 'тест')`; успех/ошибка во flash; лог в `notification_logs`.

**2.8 Дашборд** (`DashboardController` + замена заглушки `resources/js/Pages/Dashboard.vue`; маршрут `/dashboard` — сейчас closure-render, заменить на контроллер). Элементы (company-scoped, §13.1/строка 278):
1. счётчики: новых / релевантных (company_tenders) за окно (по умолчанию 30 дней — то же, что у графика п.5);
2. блок высокоприоритетных закупок (level=high, топ по score);
3. ближайшие дедлайны (deadline_at ASC, не истёкшие);
4. последние запуски (radar_runs + source_collect_runs компании) + ошибки источников;
5. **график динамики найденных** — агрегат `company_tenders` по `date(first_matched_at)` за N дней (напр. 30); лёгкий график (Chart.js через npm ИЛИ inline-SVG — решить в плане, предпочтение — без тяжёлой зависимости).
- **Отчёты/экспорт (§13.7) — этап B.**

## 3. Поток данных

Матчинг (collect→fanout из A6 ИЛИ ручной триггер 2.6) → `MatchProfileService` создаёт/обновляет `tender_match` → если score≥threshold → `NotifyJob` (очередь notifications) → `NotificationDecider` (антидубль §9) → `TelegramNotificationPresenter` (§14.1) → `TelegramGateway::sendMessage` → `notification_logs` + `radar_runs.notified_count++`.

## 4. Вне скоупа A7 (этап B)
Inline-кнопки Telegram (статусы из чата), email/web-каналы (database notifications, колокольчик), webhook-канал, AI-оценка (`ai_assessment`), отчёты §13.7 + экспорт XLSX/CSV/PDF/MD/JSON, per-profile scheduler (`schedule_type`).

## 5. Тестирование (спека §13)
- `NotifyJob` на `FakeTelegramGateway`: формат §14.1 (все поля), happy-path лог `sent`+notified_count++, no-chat_id → `failed`, транспорт-ошибка → `failed`+ретрай.
- `NotificationDecider`: первый раз → слать; повтор без изменений → НЕ слать; **каждый** ре-триггер по отдельности (цена ±10%, дедлайн, терминальный source_status, пересечение порога вверх) → слать.
- Тестовое сообщение из настроек; ручной радар-триггер (диспатч MatchProfileJob, гейт); данные дашборда + точки графика.
- **Tenant-изоляция в HTTP И в джобах** (§13/строка 303): NotifyJob/дашборд компании А не видят данные Б — обязательный тест.

## 6. Конфигурация / окружение
- `config/services.php` → `telegram.bot_token` (env `TELEGRAM_BOT_TOKEN`), опц. `telegram.api_base` (для тестов). Очередь `notifications` в `config/queue.php` (+ Horizon supervisor, как `collect`).
- `.env.example`: `TELEGRAM_BOT_TOKEN=`.

## 7. Прод-долг (НЕ блокер dev)
- **Живой бот ЕИС-стиля:** создать Telegram-бота (@BotFather), получить токен, привязать `telegram_chat_id` компаний, провести live smoke-тест — отдельный шаг по готовности (как токен ЕИС в A6). Сборка и тесты A7 — на `FakeTelegramGateway`/Http::fake без живой сети.
- Точные требования Telegram Bot API (rate limits 30 msg/s, формат разметки, длина сообщения 4096) — учесть при боевом включении.

## 8. Принципы (как A4–A6)
Полный код + TDD в плане; subagent-driven исполнение с двухэтапным Opus-ревью каждой задачи + финальное ревью ветки; tenant-изоляция (insert/upsert обходят scope — company_id явно); презентеры детерминированы; никакого AI.
