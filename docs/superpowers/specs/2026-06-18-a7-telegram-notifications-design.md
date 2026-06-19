# A7 — MAX-уведомления + ручной запуск радара + дашборд (дизайн)

**Статус:** дизайн одобрен 2026-06-18; **переориентирован Telegram → MAX 2026-06-19** (решение Романа — канал уведомлений = MAX, нац. мессенджер РФ; см. memory `tender-radar-positioning`). **План перегенерирован под MAX:** `docs/superpowers/plans/2026-06-19-a7-max-notifications.md` (2026-06-19, Workflow-трансформация + 2× Opus-ревью; старый Telegram-план помечен superseded). Готов к subagent-driven исполнению.
**Addendum к мастер-спеке** `docs/superpowers/specs/2026-06-05-tender-radar-design.md` (далее «спека») — A7 уточняет и сужает её §6 (уведомления), §9 (антидубль), §13.1 (дашборд), §14.1 (формат уведомления) до объёма этапа A7 роадмапа `docs/superpowers/plans/2026-06-05-stage-a-roadmap.md`. **A7 переопределяет канал спеки: основной канал этапа A — MAX, а не Telegram** (мастер-спека §6/§14.1 и колонка `telegram_chat_id` остаются историческими; здесь они заменяются на MAX). Имя файла спеки оставлено историческим (датированный артефакт; контент полностью на MAX) — план/роадмап/memory ссылаются на него по этому пути.

> **Почему MAX, а не Telegram.** MAX (max.ru) — российский мессенджер с полноценным Bot API; у целевой аудитории госзаказа MAX уместнее Telegram (регуляторное давление на Telegram в РФ). Лендинг `docs/marketing/Тендерный_радар_v3.html` уже ведёт MAX как флагманский канал. Уведомления в коде уже абстрагированы по каналам (`NotificationChannel`, `NotifyJob`→пресентер→шлюз), поэтому MAX добавляется как канал малой доработкой.

## 0. MAX Bot API — контракт (верифицировано против dev.max.ru, 2026-06-19; точные имена/лимиты — перепроверить на проде, см. §7)
- **База:** `https://platform-api.max.ru` (конфиг). **Аутентификация:** заголовок `Authorization: {token}` (query-параметр `access_token` — deprecated).
- **Отправка сообщения:** `POST /messages?chat_id={chatId}`, тело JSON `{ "text": "...", "format": "html" }` (объект `NewMessageBody`). Для прямого пользователя — `?user_id={userId}`; мы шлём в диалог бот↔компания по `chat_id`.
- **Форматирование текста:** `format: "html"` (`<b> <i> <a href> <del>`) или `format: "markdown"` (`**bold**`, `_italic_`, `[text](url)`). Берём **HTML** (проще экранировать `< > &`).
- **Inline-кнопки:** attachment `type: "inline_keyboard"`, `payload.buttons` (2-мерный массив), типы кнопок `callback`/`link`/… → **callback-кнопки дают «статус из чата» (этап B)**.
- **Получение chat_id:** через события `bot_started`/`bot_added` в `Update` — либо webhook (`POST /subscriptions` на наш публичный URL), либо long-polling (`GET /updates`, dev). Это ключевое отличие от Telegram: id не вводят руками (см. 2.7).
- **Создание бота и токен:** через системного бота MAX (аналог @BotFather; точное имя — на проде). Токен — в `config('services.max.bot_token')`.

## 1. Скоуп A7

A7 закрывает строку роадмапа: **MAX-уведомления (полный формат §14.1), антидубль, ручной запуск радара из UI, дашборд** (с графиком динамики). Канал — только MAX, формат полный, риски/рекомендация **детерминированные** (Р14 спеки; AI — этап B).

**Уже готово в схеме/коде (A1–A6) — не создаём, потребляем:**
- таблица `notification_logs` (company_id, tender_id, search_profile_id, channel, recipient, status, payload `jsonb`, sent_at, error) + модель `NotificationLog` + енумы `NotificationChannel`/`NotificationStatus`. **NB:** `channel` и `status` — **строковые колонки** (`$table->string(...)`) с PHP-enum-кастом, НЕ PG-enum-типы → новое значение канала добавляется правкой PHP-енума **без миграции БД**.
- `search_profiles.notification_threshold` (default 60); `radar_runs.notified_count`.
- `tender_matches` (score, level, reason `jsonb`, matched_keywords, risk_flags `jsonb`, action_recommendation `enum`) — продукт Scorer (A4); презентеры `MatchReasonPresenter` + лейблы рисков/рекомендаций (A5).
- `MatchProfileService` / `MatchProfileJob` (A4) — создают/обновляют `tender_matches` и `company_tenders`. `RunTrigger::Manual` (енум). `RadarRun` (tenant-scoped).

**Изменения схемы/енумов в A7 (минимальные, аддитивные):**
- `NotificationChannel` — добавить `case Max = 'max';` (аддитивно; миграция БД не нужна — колонка строковая).
- Миграция: добавить `companies.max_chat_id` (`string nullable`). Существующая `companies.telegram_chat_id` (A1) — оставить нетронутой (легаси/возможный канал этапа B), в A7 не используется.

## 2. Компоненты (каждый — одна ответственность, тестируется изолированно)

**2.1 `MaxGateway` (интерфейс)** — `sendMessage(string $chatId, string $text, array $opts = []): void`. Транспорт за интерфейсом (паттерн `EisGateway`/`TelegramGateway` из A6).
- Боевая реализация `HttpMaxGateway` — MAX Bot API `POST {api_base}/messages?chat_id={chatId}` через Http-фасад, заголовок `Authorization: {token}`, тело `{text, format:'html'}`; `$response->throw()` (чтобы джоб-ретраи работали); токен/база из `config('services.max.*')`.
- `FakeMaxGateway` (в тестах) — пишет отправленные сообщения в память для ассертов. Биндинг в `AppServiceProvider`.
- Токен/чувствительное НЕ логируется в исключения.

**2.2 `NotificationDecider` (сервис антидубля, §9)** — решает, слать ли уведомление по паре (профиль × закупка). Канал-параметр = `max`.
- Берёт последнюю запись `notification_logs` со `status=sent` для (search_profile_id, tender_id, channel=max).
- Нет записи → **слать**.
- Есть запись → слать **только** при одном из ре-триггеров (спека §9/строка 274): цена (`tenders.price`) изменилась на **≥10%** относительно payload прошлого уведомления; изменился `deadline_at`; `source_status` стал терминальным (`completed`/`cancelled`); текущий балл пересёк `notification_threshold` **снизу вверх** (прошлый балл < порога, текущий ≥). Иначе — **не слать**.
- Для сравнения payload прошлого уведомления хранит снимок: price, deadline_at, source_status, score (см. 2.3).

**2.3 `NotifyJob` (queued, очередь `notifications`)** — ядро.
- Вход: `tenderMatchId` (скаляр; сериализуемость, как джобы A6).
- Загружает `tender_match` (+ профиль, закупку, компанию `withoutTenancy` — глобальный диспетчер). Если у компании нет `max_chat_id` → лог `notification_logs(status=failed, error='no chat_id')` и выход.
- `NotificationDecider` решает — если нет → выход без отправки (без лога-дубля).
- Рендер текста презентером (2.4) → `MaxGateway::sendMessage` → лог `notification_logs(channel=max, recipient=chat_id, status=sent, payload={price,deadline_at,source_status,score,...}, sent_at)`; при исключении транспорта → `status=failed, error=...` (джоб ретраится). Инкремент `radar_runs.notified_count` атомарно.
- `tries=3`, backoff; `after_commit` (чтобы видеть зафиксированный match).

**2.4 `MaxNotificationPresenter`** — рендер формата **§14.1** (спека §6/строка 270): название, сумма, регион, срок подачи, источник, релевантность (level), почему подходит (reason — через `MatchReasonPresenter`), **риски** (risk_flags, детерминированные), **рекомендация** (action_recommendation), ссылка на оригинал. MAX-разметка **HTML** (`format:'html'`), экранировать `< > &`. Inline callback-кнопки — **этап B, НЕ A7**.

**2.5 Подключение в матчинг** — в `MatchProfileService` после создания/обновления `tender_match`: если `score ≥ profile.notification_threshold` → `NotifyJob::dispatch($match->id)`. Очередь `notifications` (отдельно от `collect`, чтобы не конкурировать). Решение о ре-нотификации — внутри `NotifyJob` (через `NotificationDecider`), не в сервисе.

**2.6 Ручной запуск радара (UI)** — кнопка «Запустить радар» (канал-агностично, без изменений от исходного дизайна):
- per-направление (на странице Направлений) и по всей компании;
- → `MatchProfileJob::dispatch($profileId, RunTrigger::Manual->value, null)` (для всех активных профилей при company-wide);
- гейт `manage-company`; редирект с flash. **Per-profile cron-расписание — вне A7** (плановый матчинг уже идёт через collect→fanout из A6).

**2.7 Подключение MAX в настройках компании (UI)** — ключевое отличие от Telegram.
- **Baseline A7 (в скоупе, без публичного URL):** поле `max_chat_id` + кнопка «Отправить тестовое сообщение» → `MaxGateway::sendMessage(chat_id, 'тест')`; успех/ошибка во flash; лог в `notification_logs`. Полностью тестируется на `FakeMaxGateway`. Это совпадает с онбордингом лендинга («подключите MAX → отправьте тестовое»).
- **Авто-захват chat_id через `bot_started` (рекомендуемый follow-up, прод-coupled — НЕ в baseline A7):** пользователь открывает бота (`max.ru/имя_bot` / QR) → «Начать» → MAX присылает `Update(bot_started)` с `chat_id`; наш приёмник (`MaxUpdateController` webhook через `POST /subscriptions` ИЛИ scheduled `GET /updates`-поллер для dev) сопоставляет по deep-link start-payload с компанией и сохраняет `max_chat_id` автоматически. Это MAX-нативный UX «без ручного ввода id». Вынесено из baseline, т.к. webhook требует публичного URL/подписки (прод-долг, §7), а поллер — отдельный компонент сверх минимального скоупа A7.

**2.8 Дашборд** (`DashboardController` + замена заглушки `resources/js/Pages/Dashboard.vue`; маршрут `/dashboard` — сейчас closure-render, заменить на контроллер). Канал-агностично, без изменений. Элементы (company-scoped, §13.1/строка 278):
1. счётчики: новых / релевантных (company_tenders) за окно (по умолчанию 30 дней — то же, что у графика п.5);
2. блок высокоприоритетных закупок (level=high, топ по score);
3. ближайшие дедлайны (deadline_at ASC, не истёкшие);
4. последние запуски (radar_runs + source_collect_runs компании) + ошибки источников;
5. **график динамики найденных** — агрегат `company_tenders` по `date(first_matched_at)` за N дней (напр. 30); лёгкий график (inline-SVG, без тяжёлой зависимости — предпочтение).
- **Отчёты/экспорт (§13.7) — этап B.**

## 3. Поток данных

Матчинг (collect→fanout из A6 ИЛИ ручной триггер 2.6) → `MatchProfileService` создаёт/обновляет `tender_match` → если score≥threshold → `NotifyJob` (очередь notifications) → `NotificationDecider` (антидубль §9) → `MaxNotificationPresenter` (§14.1) → `MaxGateway::sendMessage` (`POST platform-api.max.ru/messages?chat_id=…`) → `notification_logs` + `radar_runs.notified_count++`.

## 4. Вне скоупа A7 (этап B)
Авто-захват chat_id через `bot_started` (webhook/поллер, 2.7); inline callback-кнопки MAX (статусы из чата); email/web-каналы (database notifications, колокольчик); webhook-канал в CRM; AI-оценка (`ai_assessment`); отчёты §13.7 + экспорт; per-profile scheduler.

## 5. Тестирование (спека §13)
- `NotifyJob` на `FakeMaxGateway`: формат §14.1 (все поля), happy-path лог `sent`+notified_count++, no-chat_id → `failed`, транспорт-ошибка → `failed`+ретрай.
- `HttpMaxGateway` на `Http::fake`: верный URL (`platform-api.max.ru/messages?chat_id=…`), заголовок `Authorization`, тело `{text, format:'html'}`, `throw()` на 4xx/5xx; токен не утекает в исключение.
- `NotificationDecider`: первый раз → слать; повтор без изменений → НЕ слать; **каждый** ре-триггер по отдельности (цена ±10%, дедлайн, терминальный source_status, пересечение порога вверх) → слать.
- Тестовое сообщение из настроек; ручной радар-триггер (диспатч MatchProfileJob, гейт); данные дашборда + точки графика.
- **Tenant-изоляция в HTTP И в джобах** (§13/строка 303): NotifyJob/дашборд компании А не видят данные Б — обязательный тест.

## 6. Конфигурация / окружение
- `config/services.php` → `max.bot_token` (env `MAX_BOT_TOKEN`), `max.api_base` (default `https://platform-api.max.ru`, переопределяемо для тестов). Очередь `notifications` в `config/queue.php` (+ Horizon supervisor, как `collect`).
- `.env.example`: `MAX_BOT_TOKEN=`.

## 7. Прод-долг (НЕ блокер dev)
- **Живой бот MAX (ЕИС-стиль):** создать бота через системного бота MAX, получить API-токен, подключить компании (baseline — ввести `max_chat_id`; follow-up — авто-захват через `bot_started`/`POST /subscriptions` на публичный URL), провести live smoke-тест — отдельный шаг по готовности (как токен ЕИС в A6). Сборка и тесты A7 — на `FakeMaxGateway`/`Http::fake` без живой сети.
- **Прод-gate (как F5 ЕИС): сверить точные имена/контракты MAX Bot API против dev.max.ru ДО боевого включения** — путь/квери `POST /messages?chat_id`, формат заголовка `Authorization`, поля `NewMessageBody`, структура `Update(bot_started)`, имя системного бота-создателя, rate limits и максимальная длина сообщения. Контракт §0 — рабочая гипотеза по dev.max.ru (2026-06-19), не верифицирован против живого API.

## 8. Принципы (как A4–A6)
Полный код + TDD в плане; subagent-driven исполнение с двухэтапным Opus-ревью каждой задачи + финальное ревью ветки; tenant-изоляция (insert/upsert обходят scope — company_id явно); презентеры детерминированы; никакого AI.

## 9. Δ перегенерации плана — ВЫПОЛНЕНО 2026-06-19 (запись для истории)
План перегенерирован под MAX в `docs/superpowers/plans/2026-06-19-a7-max-notifications.md` (старый Telegram-план `2026-06-18-a7-telegram-notifications.md` помечен superseded). Что было изменено Telegram→MAX (затронуло код/тесты всех 9 задач):
- **C1 транспорт/конфиг (T1):** `TelegramGateway`→`MaxGateway`, `HttpTelegramGateway`→`HttpMaxGateway` (URL `api.telegram.org/bot{token}/sendMessage` → `platform-api.max.ru/messages?chat_id=…`; auth-заголовок `Authorization`; тело `{text,format:'html'}`), `FakeTelegramGateway`→`FakeMaxGateway`; `config/services.telegram.*`→`services.max.*`; env `TELEGRAM_BOT_TOKEN`→`MAX_BOT_TOKEN`. Добавить миграцию `companies.max_chat_id` + `NotificationChannel::Max`.
- **C2 ядро:** `TelegramNotificationPresenter`→`MaxNotificationPresenter` (разметка Telegram MarkdownV2/HTML → MAX HTML, перепроверить экранирование); `NotificationDecider`/`NotifyJob` — канал `telegram`→`max`, чтение/запись `max_chat_id` вместо `telegram_chat_id`, лог `channel='max'`.
- **C3 UI:** настройки компании — поле/тест-сообщение `telegram_chat_id`→`max_chat_id` (текст «Подключить MAX»); ручной радар-триггер (T6) — без изменений.
- **C4 дашборд:** без изменений (канал-агностично; заменить словесные упоминания «Telegram»→«MAX»).
- **Тесты:** все `FakeTelegramGateway`/`Http::fake api.telegram.org` → MAX-аналоги; ассерты URL/заголовка/тела под §0.
- **Имя файлов:** план перегенерирован как `2026-06-19-a7-max-notifications.md` (MAX-имя). Имя СПЕКИ оставлено историческим `2026-06-18-a7-telegram-notifications-design.md` (датированный артефакт, контент=MAX) — решено НЕ переименовывать (переименование оборвало бы ссылку старого Telegram-плана ради косметики). Имя ветки `a7-telegram-notifications` — косметика, оставлено.
