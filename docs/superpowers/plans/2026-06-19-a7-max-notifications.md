# A7 — MAX-уведомления + ручной запуск радара + дашборд — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: используй `superpowers:subagent-driven-development` (рекомендуется) или `superpowers:executing-plans` для исполнения задача-за-задачей. Шаги в чекбокс-синтаксисе (`- [ ]`).

**Goal:** Релевантная закупка (балл ≥ `notification_threshold`) автоматически уходит в MAX компании полным форматом §14.1; суперадмин/менеджер запускает радар вручную из UI; кабинет открывается дашбордом с динамикой.

**Architecture:** Транспорт MAX за интерфейсом `MaxGateway` (боевой `HttpMaxGateway` + `FakeMaxGateway`, биндинг как `EisGateway` из A6). `MatchProfileService` после создания match с баллом ≥ порога диспатчит `NotifyJob` (очередь `notifications`), который через `NotificationDecider` (антидубль §9) рендерит §14.1 (`MaxNotificationPresenter`, детерминированные риски/рекомендация — AI вне A7) и шлёт через gateway, логируя в `notification_logs`. Ручной радар — `RadarRunController` → `MatchProfileJob(Manual)`. Дашборд — `DashboardController` (company-scoped) + `Dashboard.vue` (inline-SVG график динамики).

**Tech Stack:** Laravel 13.14, Inertia/Vue 3, PG16, Redis (Horizon), Pest. MAX Bot API (sendMessage). Без новых npm-зависимостей (график — inline-SVG).

## Global Constraints

Спека: `docs/superpowers/specs/2026-06-18-a7-telegram-notifications-design.md` + мастер `docs/superpowers/specs/2026-06-05-tender-radar-design.md`. Каждая задача неявно включает эти ограничения:

- **Формат §14.1 (MAX):** полный — название, сумма, регион, срок подачи, источник, релевантность (level), почему подходит (reason через `MatchReasonPresenter`), **риски** (`risk_flags`), **рекомендация** (`action_recommendation`), ссылка. MAX HTML-разметка (`format:'html'`) + экранирование HTML-спецсимволов (`< > &`; в href-атрибуте — кавычки). Риски/рекомендация **ДЕТЕРМИНИРОВАННЫЕ** (Р14). **AI — этап B, в A7 НЕ использовать.**
- **Триггер уведомления:** балл ≥ `search_profiles.notification_threshold` (сравнение **≥**), после создания/обновления `tender_match`. Получатель — **per-компания** `companies.max_chat_id`.
- **Антидубль (§9):** уведомление по паре (профиль × закупка) — один раз (проверка `notification_logs`, channel=max, status=sent). Повторное — ТОЛЬКО при ре-триггере: цена `tenders.price` изменилась на ≥10% относительно снимка прошлого уведомления; изменился `deadline_at`; `source_status` стал терминальным; балл пересёк `notification_threshold` снизу вверх.
- **Очередь:** `NotifyJob` исполняется на соединении И очереди `notifications` (`$this->onConnection('notifications')->onQueue('notifications')` — НЕ типизированные свойства, трейт Queueable их объявляет untyped → PHP fatal). `config/queue.php` соединение `notifications` и Horizon `supervisor-notifications` — оба на `connection: 'notifications'`, `queue: ['notifications']` (выровнено, как redis-long/collect в A6).
- **Tenant-изоляция (§13):** company-scoped везде; insert/upsert обходят scope — `company_id` явно; `withoutTenancy()` для глобальных джобов (NotifyJob грузит match по company_id). Суперадмин (`company_id=null`) → ранний guard (пустой дашборд, без утечки данных всех компаний). Обязательный тест изоляции в HTTP И в джобах.
- **Схема-дельта C1 (владение — Task 1):** НОВАЯ миграция `database/migrations/<ts>_add_max_chat_id_to_companies.php` добавляет `$table->string('max_chat_id')->nullable()`; `down()` вызывает `dropColumn`. Колонка `companies.telegram_chat_id` — **оставить нетронутой** (легаси). Правка `app/Enums/NotificationChannel.php` — добавить `case Max = 'max';` аддитивно; БД-миграция для `notification_logs.channel` **не нужна** (строковая колонка, не PG-enum).
- **MAX Bot API (§0 спеки):** `POST {api_base}/messages?chat_id={chatId}`, токен в заголовке `Authorization: {token}` (НЕ в URL — не нужно вырезать из исключений), тело `{text, format:'html'}`. Заголовок `Authorization` **не дампится в лог/исключение**. `config/services.max.bot_token` + `config/services.max.api_base` (default `https://platform-api.max.ru`), env `MAX_BOT_TOKEN`.
- **Вне скоупа A7 (этап B):** inline callback-кнопки MAX, авто-захват `max_chat_id` через `bot_started`/webhook, email/web/webhook-каналы, AI-оценка, отчёты §13.7 + экспорт, per-profile scheduler (`schedule_type`).
- **Процесс:** TDD (полный код в каждом шаге), `./vendor/bin/pint --test` repo-wide зелёный, phpstan L6 (app/) = 0, каждая задача — самодостаточный коммит с trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## Межкластерные контракты (исполнитель ОБЯЗАН соблюсти — выявлено при драфтинге)

1. **`FakeMaxGateway` определяется в Task 1 — это АВТОРИТЕТНЫЙ контракт.** Фактический API фейка: публичный `array $sent` (буфер `{chatId,text,opts}`), `?\Throwable $throwOnSend` (смоделировать ошибку транспорта), `messagesTo(string $chatId): array`. Тесты Task 4/7 используют именно этот API.
2. **Глобальный bind Fake в Feature-тестах (Task 1, шаг 1.2a).** Регистрация `Feature` в `tests/Pest.php` заменяется на версию с `->beforeEach(fn () => $this->app->instance(MaxGateway::class, new FakeMaxGateway))`. Без этого после хука Task 5 существующий `MatchProfileServiceTest` (кейс score=60 = дефолтный порог, очередь sync) выполнил бы `NotifyJob` инлайн → живой HTTP к MAX. `NotifyJob` на `onConnection('notifications')` (redis, не sync) — первая линия защиты; глобальный bind фейка — гарантированная вторая. Тесты, ассертящие отправку, биндят СВОЙ экземпляр фейка локально (перекрывает дефолтный).
3. **`TenderMatch` НЕ имеет связи `company()`** — только `tender()`/`searchProfile()`. В `NotifyJob` загружать компанию через `Company::find($match->company_id)` (Company без tenant-scope).
4. **`routes/web.php` — общий для Task 6/7/8.** Правки строго ADDITIVE/SURGICAL (без переписывания файла). Статические маршруты (`profiles/run-company`) объявлять ВЫШЕ параметрических (`profiles/{profile}/run`). Task 8 заменяет closure `/dashboard` на контроллер, сохраняя `middleware('auth')` + `name('dashboard')`.
5. **Реальные Vue-страницы:** направления — `resources/js/Pages/Profiles/Index.vue`; настройки компании — `resources/js/Pages/Company/Edit.vue` (поле `max_chat_id` + правило в `CompanySettingsRequest` — Task 7 добавляет поле и кнопку «Отправить тестовое сообщение»). Бывшее поле `telegram_chat_id` на этой странице — **не трогать**, Task 7 добавляет рядом новое поле `max_chat_id`.
6. **Схема-дельта — владение Task 1:** миграция `add_max_chat_id_to_companies` и правка `NotificationChannel` (добавить `Max`) создаются в Task 1. Все последующие задачи (T2–T9) опираются на уже существующую колонку и enum-кейс — не пересоздают их. Тест «миграция применяется и колонка существует; enum содержит Max» — также в Task 1.

---



### Task 1 — Транспорт MAX + конфиг (кластер C1)

Источник истины: `docs/superpowers/specs/2026-06-18-a7-telegram-notifications-design.md` §0, §2.1, §6, §7.
Паттерн — `app/Adapters/Eis/EisGateway.php` + `SoapEisGateway.php` (интерфейс + HTTP-реализация через Http-фасад, `$response->throw()` для джоб-ретраев) и его биндинг в `AppServiceProvider::register`.

Самодостаточный коммит. Trailer:
`Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`

**Files:**
- Create: `app/Notifications/MaxGateway.php`
- Create: `app/Notifications/HttpMaxGateway.php`
- Create: `app/Notifications/FakeMaxGateway.php`
- Modify: `app/Providers/AppServiceProvider.php` (ТОЛЬКО `+` биндинг в `register()`, не трогая bind EisGateway)
- Modify: `config/services.php` (+ `'max'`)
- Modify: `config/queue.php` (+ соединение `'notifications'`)
- Modify: `config/horizon.php` (+ `supervisor-notifications` в `production` и `local`)
- Modify: `.env.example` (+ `MAX_BOT_TOKEN=`, `MAX_API_BASE=`)
- Modify: `tests/Pest.php` (+ регистрация `Unit/Max`; + глобальный bind `FakeMaxGateway` в `Feature`-регистрации через `beforeEach` — см. шаг 1.2a)
- Test: `tests/Unit/Max/HttpMaxGatewayTest.php`
- Create: `database/migrations/<ts>_add_max_chat_id_to_companies.php` (C1 владеет этой миграцией)
- Modify: `app/Enums/NotificationChannel.php` (+ `case Max = 'max';`)
- Test: `tests/Unit/Max/NotificationChannelTest.php` (enum-кейсы Max, без БД)
- Test: `tests/Feature/Schema/MaxChatIdMigrationTest.php` (миграция `max_chat_id` — Feature, RefreshDatabase)

**Interfaces:**
- Produces: `App\Notifications\MaxGateway::sendMessage(string $chatId, string $text, array $opts = []): void` — потребляется Task'ами C2/C3 (`NotifyJob`, `CompanySettingsController` «тестовое сообщение»).
- Produces: биндинг контейнера `MaxGateway → HttpMaxGateway`; в тестах сваппится на `App\Notifications\FakeMaxGateway`.
- Produces: config-ключи `services.max.bot_token`, `services.max.api_base`; queue-соединение `notifications`; Horizon supervisor `supervisor-notifications`.
- Produces: колонка `companies.max_chat_id` (string nullable); `NotificationChannel::Max` ('max').
- Consumes: ничего из A7 (фундамент кластера). Использует Http-фасад и config — как `SoapEisGateway`.

**Решения по контракту (зафиксированы, не «выбрать в плане»):**
- `FakeMaxGateway` — в `app/Notifications/` (рядом с интерфейсом, как `SoapEisGateway` рядом с `EisGateway`), НЕ в `tests/Support/`. Причина: `tests/Support/EisFixtures.php` — это функция-фабрика ZIP (`makeEisArchive`), другой жанр; класс-Fake в `app/` виден и Feature, и Unit без правок `composer.json` autoload-dev, swap `$this->app->instance(MaxGateway::class, $fake)` чист.
- `notifications`-соединение: формат как `redis`/`redis-long`, но очередь зашита `'notifications'` (а не из env), `retry_after => 90`. Так dispatch без явного `onQueue` всё равно попадёт в очередь `notifications`.
- Horizon `supervisor-notifications`: `connection => 'notifications'`, `queue => ['notifications']` (выровнено с соединением `notifications` из `config/queue.php` и NotifyJob `->onConnection('notifications')->onQueue('notifications')`, как redis-long/collect в A6).
- `HttpMaxGateway` передаёт `format:'html'` всегда (MAX HTML — единственный формат A7; presenter из C2 формирует разметку). Дополнительные `$opts` прокидываются в тело поверх дефолтов.
- Токен передаётся в заголовке `Authorization` — не попадает в URL; заголовок не дампится в лог/исключение.

**TDD-шаги:**

- [ ] **1.1 (RED) Тест транспорта.** Создать `tests/Unit/Max/HttpMaxGatewayTest.php` (Http::fake-паттерн из `tests/Unit/Eis/SoapEisGatewayTest.php`):

```php
<?php

use App\Notifications\HttpMaxGateway;
use App\Notifications\MaxGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('sendMessage шлёт POST на MAX Bot API /messages с chat_id в query и токеном в заголовке', function () {
    config([
        'services.max.bot_token' => 'MY_MAX_TOKEN',
        'services.max.api_base'  => 'https://platform-api.max.ru',
    ]);

    Http::fake([
        'platform-api.max.ru/*' => Http::response(['message_id' => 1], 200),
    ]);

    $gateway = new HttpMaxGateway;
    $gateway->sendMessage('123456', 'привет');

    Http::assertSent(function (Request $request) {
        $url = (string) $request->url();
        return $request->method() === 'POST'
            && str_contains($url, '/messages')
            && str_contains($url, 'chat_id=123456')
            && $request->header('Authorization')[0] === 'MY_MAX_TOKEN'
            && $request['text'] === 'привет'
            && $request['format'] === 'html';
    });
});

it('sendMessage прокидывает дополнительные opts в тело', function () {
    config([
        'services.max.bot_token' => 'MY_MAX_TOKEN',
        'services.max.api_base'  => 'https://platform-api.max.ru',
    ]);

    Http::fake([
        'platform-api.max.ru/*' => Http::response(['message_id' => 1], 200),
    ]);

    (new HttpMaxGateway)->sendMessage('123456', 'plain', ['notify' => false]);

    Http::assertSent(fn (Request $request) => $request['notify'] === false);
});

it('sendMessage использует api_base по умолчанию, если не задан', function () {
    config(['services.max.bot_token' => 'MY_MAX_TOKEN', 'services.max.api_base' => null]);

    Http::fake(['platform-api.max.ru/*' => Http::response(['message_id' => 1], 200)]);

    (new HttpMaxGateway)->sendMessage('1', 'x');

    Http::assertSent(fn (Request $request) => str_starts_with((string) $request->url(), 'https://platform-api.max.ru/'));
});

it('sendMessage бросает RequestException при HTTP 500 (для джоб-ретраев)', function () {
    config(['services.max.bot_token' => 'MY_MAX_TOKEN']);

    Http::fake(['platform-api.max.ru/*' => Http::response('server error', 500)]);

    expect(fn () => (new HttpMaxGateway)->sendMessage('1', 'x'))
        ->toThrow(RequestException::class);
});

it('sendMessage бросает RequestException при HTTP 400 (для джоб-ретраев)', function () {
    config(['services.max.bot_token' => 'MY_MAX_TOKEN']);

    Http::fake(['platform-api.max.ru/*' => Http::response(['error' => 'bad request'], 400)]);

    expect(fn () => (new HttpMaxGateway)->sendMessage('1', 'x'))
        ->toThrow(RequestException::class);
});

it('токен не появляется в сообщении исключения при HTTP-ошибке', function () {
    config(['services.max.bot_token' => 'SUPER_SECRET_TOKEN']);

    Http::fake(['platform-api.max.ru/*' => Http::response('fail', 500)]);

    $message = '';
    try {
        (new HttpMaxGateway)->sendMessage('1', 'x');
    } catch (RequestException $e) {
        $message = $e->getMessage();
    }

    // Токен в заголовке — не в URL, поэтому не попадает в RequestException message
    expect($message)->not->toContain('SUPER_SECRET_TOKEN');
});

it('биндинг контейнера резолвит MaxGateway в HttpMaxGateway', function () {
    expect(app(MaxGateway::class))->toBeInstanceOf(HttpMaxGateway::class);
});
```

- [ ] **1.2 (RED→зелёный гейт) Регистрация Unit/Max в Pest.** В `tests/Pest.php` добавить блок ПОСЛЕ существующего `->in('Unit/Queue')` (иначе классы без БД попробуют RefreshDatabase из Feature-биндинга нет, но базовый TestCase нужен явно — как для прочих Unit/*):

```php
pest()->extend(TestCase::class)
    ->in('Unit/Max');
```

  (Вставить между `pest()->extend(TestCase::class)->in('Unit/Queue');` и комментарием `| Expectations`.)

- [ ] **1.2a (GREEN, КРИТИЧНО) Глобальный bind FakeMaxGateway во ВСЕХ Feature-тестах.** Найти существующую регистрацию `Feature` в `tests/Pest.php` (`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');`) и ЗАМЕНИТЬ её на версию с `beforeEach`, биндящим дефолтный фейк, чтобы ни один Feature-тест не бил живой MAX API (особенно существующий `MatchProfileServiceTest` после хука Task 5, где sync-очередь выполнила бы `NotifyJob` инлайн):

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->app->instance(\App\Notifications\MaxGateway::class, new \App\Notifications\FakeMaxGateway);
    })
    ->in('Feature');
```

  > **Примечание:** тесты, которым нужно ассертить отправленные сообщения (Task 4 NotifyJobTest, Task 7 CompanyTestMessageTest), биндят СВОЙ экземпляр `FakeMaxGateway` в локальном `beforeEach` (хранят ссылку `$this->fakeGateway`) — он перекрывает дефолтный. Дефолтный bind лишь предотвращает живой HTTP в тестах, которые отправку не ассертят. Unit-тесты НЕ затронуты: `HttpMaxGatewayTest` лежит в `Unit/`, тестирует `HttpMaxGateway` напрямую через `Http::fake` — Feature-bind на него не влияет.

- [ ] **1.3 (GREEN) Интерфейс.** Создать `app/Notifications/MaxGateway.php`:

```php
<?php

namespace App\Notifications;

interface MaxGateway
{
    /**
     * Отправить сообщение в MAX-чат.
     *
     * @param  string  $chatId  целевой chat_id (companies.max_chat_id)
     * @param  string  $text  готовый текст (HTML-разметка; format:'html' выставляется транспортом)
     * @param  array<string, mixed>  $opts  доп. параметры MAX Bot API (notify, ...)
     *
     * @throws \Illuminate\Http\Client\RequestException при HTTP-ошибке транспорта
     */
    public function sendMessage(string $chatId, string $text, array $opts = []): void;
}
```

- [ ] **1.4 (GREEN) Боевая реализация.** Создать `app/Notifications/HttpMaxGateway.php`:

```php
<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Боевой транспорт MAX Bot API через Http-фасад (паттерн SoapEisGateway).
 *
 * POST {api_base}/messages?chat_id={chatId}, заголовок Authorization: {token},
 * тело JSON {text, format:'html'}. $response->throw() конвертирует HTTP 4xx/5xx
 * в RequestException, чтобы провижиненный ретрай NotifyJob (tries=3) отработал.
 *
 * Токен передаётся только в заголовке Authorization — НЕ в URL → не попадает
 * в текст RequestException и не дублируется в собственных сообщениях об ошибках.
 * Заголовок не логируется приложением явно (§0 спеки, §7 прод-долг).
 */
class HttpMaxGateway implements MaxGateway
{
    public function sendMessage(string $chatId, string $text, array $opts = []): void
    {
        $token   = (string) config('services.max.bot_token');
        $apiBase = rtrim((string) config('services.max.api_base', 'https://platform-api.max.ru'), '/');

        /** @var array<string, mixed> $payload */
        $payload = array_merge(
            ['text' => $text, 'format' => 'html'],
            $opts,
        );

        Http::withHeaders(['Authorization' => $token])
            ->asJson()
            ->post($apiBase.'/messages?chat_id='.rawurlencode($chatId), $payload)
            ->throw();
    }
}
```

- [ ] **1.5 (GREEN) Fake для тестов.** Создать `app/Notifications/FakeMaxGateway.php`:

```php
<?php

namespace App\Notifications;

/**
 * In-memory реализация MaxGateway для тестов (паттерн EisGateway-fake).
 * Сваппится через $this->app->instance(MaxGateway::class, $fake).
 * Накапливает отправленные сообщения в публичном массиве для ассертов.
 */
class FakeMaxGateway implements MaxGateway
{
    /** @var list<array{chatId: string, text: string, opts: array<string, mixed>}> */
    public array $sent = [];

    /** Если задано — sendMessage бросит это исключение (для теста транспорт-ошибки в NotifyJob). */
    public ?\Throwable $throwOnSend = null;

    public function sendMessage(string $chatId, string $text, array $opts = []): void
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        $this->sent[] = [
            'chatId' => $chatId,
            'text'   => $text,
            'opts'   => $opts,
        ];
    }

    /** @return list<array{chatId: string, text: string, opts: array<string, mixed>}> */
    public function messagesTo(string $chatId): array
    {
        return array_values(array_filter($this->sent, fn (array $m) => $m['chatId'] === $chatId));
    }
}
```

- [ ] **1.6 (GREEN) Биндинг.** В `app/Providers/AppServiceProvider.php` — добавить use и bind, НЕ трогая bind EisGateway. После строки `use App\Adapters\Eis\SoapEisGateway;`:

```php
use App\Notifications\HttpMaxGateway;
use App\Notifications\MaxGateway;
```

  В `register()`, сразу после строки `$this->app->bind(EisGateway::class, SoapEisGateway::class);`:

```php

        // MAX-транспорт: HttpMaxGateway — реальный MAX Bot API (A7)
        $this->app->bind(MaxGateway::class, HttpMaxGateway::class);
```

- [ ] **1.7 (GREEN) config/services.php.** Добавить блок `max` перед закрывающим `];` (после блока `slack`):

```php
    'max' => [
        'bot_token' => env('MAX_BOT_TOKEN'),
        'api_base'  => env('MAX_API_BASE', 'https://platform-api.max.ru'),
    ],
```

- [ ] **1.8 (GREEN) config/queue.php.** Добавить соединение `notifications` в массив `'connections'` сразу после блока `'redis-long' => [ ... ],`:

```php
        'notifications' => [
            'driver'      => 'redis',
            'connection'  => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'       => 'notifications',
            'retry_after' => (int) env('NOTIFICATIONS_QUEUE_RETRY_AFTER', 90),
            'block_for'   => null,
            'after_commit' => false,
        ],
```

- [ ] **1.9 (GREEN) config/horizon.php.** Добавить `supervisor-notifications` в `environments.production` (после `supervisor-collect`) и в `environments.local` (после `supervisor-collect`). По образцу `supervisor-collect`, но `connection => 'notifications'` (соединение из `config/queue.php`, выровнено с NotifyJob — как redis-long/collect в A6), `queue => ['notifications']`, лёгкие лимиты (короткие сообщения, не качаем архивы).

  В `production`, после закрывающего `],` блока `supervisor-collect`:

```php
            'supervisor-notifications' => [
                'connection'          => 'notifications', // соединение notifications из config/queue.php (как redis-long/collect в A6)
                'queue'               => ['notifications'],
                'balance'             => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses'        => 3,
                'maxTime'             => 0,
                'maxJobs'             => 0,
                'memory'              => 128,
                'tries'               => 1,
                'timeout'             => 30,
                'nice'                => 0,
            ],
```

  В `local`, после закрывающего `],` блока `supervisor-collect`:

```php
            'supervisor-notifications' => [
                'connection'          => 'notifications',
                'queue'               => ['notifications'],
                'balance'             => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses'        => 2,
                'maxTime'             => 0,
                'maxJobs'             => 0,
                'memory'              => 128,
                'tries'               => 1,
                'timeout'             => 30,
                'nice'                => 0,
            ],
```

  Также (опционально, согласовано с `waits` стилем) добавить в `waits` строку `'redis:notifications' => 60,` после `'redis:collect' => 300,`.

- [ ] **1.10 (GREEN) .env.example.** Добавить ТОЛЬКО MAX-группу (НЕ трогать существующий A6-блок `EIS_ENDPOINT`). Вставить отдельной группой перед существующим блоком `EIS_ENDPOINT` (~строка 71):

```
# MAX-уведомления (A7)
MAX_BOT_TOKEN=
MAX_API_BASE=https://platform-api.max.ru
```

- [ ] **1.11 (GREEN) Миграция companies.max_chat_id.** C1 владеет. Создать `database/migrations/<ts>_add_max_chat_id_to_companies.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('max_chat_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('max_chat_id');
        });
    }
};
```

  > **Заметка:** `companies.telegram_chat_id` — легаси, оставить нетронутой (возможный канал этапа B). Существующая колонка НЕ удаляется.

- [ ] **1.12 (GREEN) NotificationChannel::Max.** В `app/Enums/NotificationChannel.php` добавить аддитивно:

```php
case Max = 'max';
```

  БД-миграция для `notification_logs.channel` **не нужна** — колонка строковая (`$table->string(...)`), не PG-enum-тип.

- [ ] **1.13a (RED→GREEN) Unit-тесты enum (без БД).** Создать `tests/Unit/Max/NotificationChannelTest.php` (чистый enum, без RefreshDatabase — лежит в `Unit/Max`):

```php
<?php

use App\Enums\NotificationChannel;

it('NotificationChannel содержит кейс Max со значением max', function () {
    expect(NotificationChannel::Max->value)->toBe('max');
});

it('NotificationChannel::from корректно парсит строку max', function () {
    expect(NotificationChannel::from('max'))->toBe(NotificationChannel::Max);
});
```

- [ ] **1.13b (RED→GREEN) Feature-тест миграции (с БД).** Тест `Schema::hasColumn` требует БД — выносим в Feature (RefreshDatabase из глобальной Feature-регистрации накатит все миграции). Решение зафиксировано: создать `tests/Feature/Schema/MaxChatIdMigrationTest.php` (НЕ в `Unit/Max`, который без БД):

```php
<?php

use Illuminate\Support\Facades\Schema;

it('миграция add_max_chat_id_to_companies применима и колонка существует', function () {
    // RefreshDatabase (глобальная Feature-регистрация в tests/Pest.php) накатывает
    // все миграции, включая add_max_chat_id_to_companies — колонка должна быть.
    expect(Schema::hasColumn('companies', 'max_chat_id'))->toBeTrue();
});
```

  > **Зафиксировано:** `Unit/Max/` остаётся БЕЗ БД (только enum-кейсы — шаг 1.13a); миграцию покрывает Feature-тест `tests/Feature/Schema/MaxChatIdMigrationTest.php` (шаг 1.13b), где RefreshDatabase накатывает миграции. Отдельная регистрация `Unit/Max` с RefreshDatabase НЕ вводится.

- [ ] **1.14 (REFACTOR/проверка) Прогнать гейты.**
  - `./vendor/bin/pest tests/Unit/Max tests/Feature/Schema/MaxChatIdMigrationTest.php` — все зелёные.
  - `./vendor/bin/pint app/Notifications config/services.php config/queue.php config/horizon.php app/Providers/AppServiceProvider.php tests/Unit/Max tests/Feature/Schema tests/Pest.php database/migrations app/Enums/NotificationChannel.php` (CI гоняет `pint --test` repo-wide — формат обязан совпасть).
  - `./vendor/bin/phpstan analyse app/Notifications app/Providers app/Enums` (L6 для `app/`; убедиться, что `FakeMaxGateway::$throwOnSend` и дженерик-докблоки проходят).
  - Smoke биндинга: `php artisan tinker --execute="dump(get_class(app(App\Notifications\MaxGateway::class)));"` → `HttpMaxGateway`.


---


# A7 — План реализации, кластер C2 (Tasks 2–5)

Источник истины: `docs/superpowers/specs/2026-06-18-a7-telegram-notifications-design.md` (§2.2–2.5, §3, §5).
Мастер-спека: `docs/superpowers/specs/2026-06-05-tender-radar-design.md` (§9 антидубль, §14.1/§9 формат).
Стиль — `docs/superpowers/plans/2026-06-17-a6-eis-adapter.md`.

**Предпосылки из C1 (НЕ владение C2, потребляем):**
- `App\Notifications\MaxGateway` — интерфейс `sendMessage(string $chatId, string $text, array $opts = []): void` (C1 Task 1).
- `App\Notifications\FakeMaxGateway` — фейк с буфером отправленных сообщений и ассертами; биндится в `AppServiceProvider` под тесты (C1 Task 1, шаг 1.5). Класс лежит в `app/Notifications/` (рядом с интерфейсом), НЕ в `tests/Support/`.
- Очередь `notifications` в `config/queue.php` (C1).

> ВАЖНО (урок A6): в джобе НЕ объявляем `public string $connection`/`$queue` типизированными — трейт `Queueable` уже определяет эти свойства, повторное типизированное объявление = PHP fatal. Используем `$this->onConnection(...)->onQueue(...)` в конструкторе (как `ProcessChunkJob`).

> ВАЖНО (контракты): `TenderMatch`, `NotificationLog`, `RadarRun`, `SearchProfile` используют трейт `BelongsToCompany` (глобальный scope по `company_id`). В джобе/сервисе диспетчера TenantContext не задан → читаем через `Model::withoutTenancy()`, пишем явный `company_id`. `Tender` — глобальная таблица БЕЗ трейта. `Tender::$price` — `numeric-string|null` (cast `decimal:2`). `notification_logs` колонки: `company_id, tender_id, search_profile_id, channel, recipient, status, payload(jsonb default '{}'), sent_at, error`. `radar_runs.notified_count` — целочисленный счётчик (без явного каста, integer).

---

### Task 2: MaxNotificationPresenter — рендер уведомления §14.1

**Files:**
- Create `app/Notifications/MaxNotificationPresenter.php`
- Create `tests/Unit/Notifications/MaxNotificationPresenterTest.php`
- Modify `tests/Pest.php` — зарегистрировать `pest()->extend(Tests\TestCase::class)->in('Unit/Notifications')` ТОЛЬКО если каталог `Unit/Notifications` ещё не покрыт существующей регистрацией (проверить glob `->in('Unit')`; если есть общий `Unit` — НЕ дублировать). Task 2 — единственный владелец этой регистрации в C2.

**Interfaces:**
- Consumes: `App\Models\TenderMatch` (+ загруженные `tender`, его `source`), `App\Scoring\MatchReasonPresenter` (статические `lines(array): array`, `risks(array): array`), енумы `RelevanceLevel::label()`, `ActionRecommendation::label()`.
- Produces: `MaxNotificationPresenter::render(TenderMatch $match): string` — готовый HTML-текст MAX (`format:'html'`).

**Решения по формату (детерминированы):**
- Разметка — **MAX HTML** (`format:'html'`): экранируем `&`, `<`, `>` функцией `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')` для всех динамических строк. HTML проще MarkdownV2 (не нужно экранировать `. ! - ( )` и т.п.).
- Поля по §14.1: заголовок (эмодзи + название), сумма, регион, срок подачи (`deadline_at`), источник (`source.name`), релевантность (`level->label()`), «Почему подходит» (reason → `MatchReasonPresenter::lines`), риски (`risk_flags` → `MatchReasonPresenter::risks`), рекомендация (`action_recommendation->label()`), ссылка (`source_url` как `<a>`).
- Сумма: `price === null` → «не указана»; иначе формат «1 500 000,00 ₽» (`number_format((float)$price, 2, ',', ' ')`). Регион/дедлайн при null → «не указан(а)». Дедлайн — `deadline_at?->format('d.m.Y H:i')`.
- Reason/risks при пустом массиве — секцию не печатаем (детерминированность: фикстура задаёт непустые).

- [ ] **Write failing test**

Создать `tests/Unit/Notifications/MaxNotificationPresenterTest.php`:

```php
<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Notifications\MaxNotificationPresenter;
use Illuminate\Support\Carbon;

it('рендерит все поля §14.1 детерминированно', function () {
    $source = new Source(['code' => 'eis', 'name' => 'ЕИС (zakupki.gov.ru)']);

    $tender = new Tender([
        'title' => 'Поставка кранов <мостовых> & опорных',
        'region' => 'Москва',
        'price' => '1500000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_url' => 'https://zakupki.gov.ru/notice/0173200001426000001',
    ]);
    $tender->setRelation('source', $source);

    $match = new TenderMatch([
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [
            ['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'кран'],
            ['rule' => 'priority_region', 'points' => 10, 'detail' => 'Москва'],
        ],
        'risk_flags' => ['deadline_soon', 'no_price'],
    ]);
    $match->setRelation('tender', $tender);

    $text = MaxNotificationPresenter::render($match);

    // Название — экранировано (HTML-спецсимволы не ломают разметку)
    expect($text)->toContain('Поставка кранов &lt;мостовых&gt; &amp; опорных')
        ->and($text)->not->toContain('Поставка кранов <мостовых>')
        // Сумма
        ->and($text)->toContain('1 500 000,00 ₽')
        // Регион
        ->and($text)->toContain('Москва')
        // Срок подачи
        ->and($text)->toContain('01.07.2026 18:00')
        // Источник
        ->and($text)->toContain('ЕИС (zakupki.gov.ru)')
        // Релевантность
        ->and($text)->toContain('Средняя')
        // Почему подходит (reason через MatchReasonPresenter)
        ->and($text)->toContain('Ключ в названии: «кран» (+20)')
        ->and($text)->toContain('Приоритетный регион: Москва (+10)')
        // Риски
        ->and($text)->toContain('Скоро дедлайн')
        ->and($text)->toContain('Цена не указана')
        // Рекомендация
        ->and($text)->toContain('Посмотреть ТЗ')
        // Ссылка
        ->and($text)->toContain('https://zakupki.gov.ru/notice/0173200001426000001');
});

it('подставляет заглушки при null-полях', function () {
    $source = new Source(['code' => 'manual', 'name' => 'Ручной импорт']);
    $tender = new Tender([
        'title' => 'Закупка без деталей',
        'region' => null,
        'price' => null,
        'deadline_at' => null,
        'source_url' => 'https://example.test/x',
    ]);
    $tender->setRelation('source', $source);

    $match = new TenderMatch([
        'score' => 40,
        'level' => RelevanceLevel::Weak,
        'action_recommendation' => ActionRecommendation::Monitor,
        'reason' => [],
        'risk_flags' => [],
    ]);
    $match->setRelation('tender', $tender);

    $text = MaxNotificationPresenter::render($match);

    expect($text)->toContain('Цена: не указана')
        ->and($text)->toContain('Регион: не указан')
        ->and($text)->toContain('Срок подачи: не указан')
        ->and($text)->toContain('Слабая')
        ->and($text)->toContain('Мониторить');
});
```

> Примечание по тесту: модели создаются без БД (`new Model([...])` + `setRelation`) — Unit без RefreshDatabase. Касты `reason`/`risk_flags`/`level`/`action_recommendation` применяются Eloquent при чтении атрибута и для in-memory модели (массив/енум возвращаются как объявлено). `price` хранится строкой — презентер приводит к float сам.

- [ ] **Implement**

Создать `app/Notifications/MaxNotificationPresenter.php`:

```php
<?php

namespace App\Notifications;

use App\Models\TenderMatch;
use App\Scoring\MatchReasonPresenter;

/**
 * Рендерит MAX-уведомление о матче по формату §14.1 спеки.
 * Разметка — MAX HTML (format:'html'). Все динамические строки
 * экранируются htmlspecialchars (ENT_QUOTES, UTF-8), чтобы спецсимволы
 * из названий/деталей не ломали разметку. Экранируются только HTML-спецсимволы:
 * & < > (и кавычки для href-атрибутов). MarkdownV2-escape не используется.
 */
final class MaxNotificationPresenter
{
    public static function render(TenderMatch $match): string
    {
        $tender = $match->tender;
        $source = $tender->source;

        $title = self::esc((string) $tender->title);
        $price = $tender->price === null
            ? 'не указана'
            : number_format((float) $tender->price, 2, ',', ' ').' ₽';
        $region = $tender->region !== null && $tender->region !== ''
            ? self::esc((string) $tender->region)
            : 'не указан';
        $deadline = $tender->deadline_at?->format('d.m.Y H:i') ?? 'не указан';
        $sourceName = $source !== null
            ? self::esc((string) $source->name)
            : 'не указан';
        $url = self::esc((string) $tender->source_url);

        $lines = [];
        $lines[] = '<b>🔔 '.$title.'</b>';
        $lines[] = '';
        $lines[] = '<b>Цена:</b> '.self::esc($price);
        $lines[] = '<b>Регион:</b> '.$region;
        $lines[] = '<b>Срок подачи:</b> '.self::esc($deadline);
        $lines[] = '<b>Источник:</b> '.$sourceName;
        $lines[] = '<b>Релевантность:</b> '.self::esc($match->level->label()).' ('.$match->score.')';

        $reasonLines = MatchReasonPresenter::lines($match->reason);
        if ($reasonLines !== []) {
            $lines[] = '';
            $lines[] = '<b>Почему подходит:</b>';
            foreach ($reasonLines as $reason) {
                $lines[] = '• '.self::esc($reason);
            }
        }

        $risks = MatchReasonPresenter::risks($match->risk_flags);
        if ($risks !== []) {
            $lines[] = '';
            $lines[] = '<b>Риски:</b>';
            foreach ($risks as $risk) {
                $lines[] = '• '.self::esc($risk);
            }
        }

        $lines[] = '';
        $lines[] = '<b>Рекомендация:</b> '.self::esc($match->action_recommendation->label());
        $lines[] = '';
        $lines[] = '<a href="'.$url.'">Открыть закупку</a>';

        return implode("\n", $lines);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
```

- [ ] `./vendor/bin/pint --test` (repo-wide) + `phpstan analyse app/` (L6) проходят
- [ ] Коммит: `A7 C2 Task 2: MaxNotificationPresenter — рендер §14.1 (MAX HTML, экранирование)` (trailer Co-Authored-By)

---

### Task 3: NotificationDecider — антидубль §9

**Files:**
- Create `app/Services/Notification/NotificationDecider.php`
- Create `tests/Feature/Notifications/NotificationDeciderTest.php` (Feature — нужна БД)

**Interfaces:**
- Consumes: `App\Models\TenderMatch` (загружен `tender`), `App\Models\NotificationLog` (последняя `sent`-запись по `search_profile_id × tender_id × channel=max`), енумы `NotificationChannel`, `NotificationStatus`, `TenderSourceStatus`.
- Produces: `NotificationDecider::shouldNotify(TenderMatch $match): bool`.

**Логика (§9, строка 274 мастер-спеки + §2.2 A7):**
1. Берём последнюю `NotificationLog::withoutTenancy()` со `status=sent`, `channel=max`, `search_profile_id=$match->search_profile_id`, `tender_id=$match->tender_id`, `latest('id')`.
2. Нет записи → `true`.
3. Есть — `true` только при одном из ре-триггеров относительно `payload` прошлой записи (снимок `{price, deadline_at, source_status, score}`):
   - **цена**: текущая `tender.price` отличается от прошлой `payload.price` на **≥10%** (база — прошлая цена; если прошлая 0/null, а текущая задана — считаем изменением);
   - **дедлайн**: текущий `tender.deadline_at` (ISO) ≠ `payload.deadline_at`;
   - **терминальный source_status**: текущий `tender.source_status` ∈ {`completed`,`cancelled`} И прошлый `payload.source_status` НЕ терминальный (переход в терминальное);
   - **порог снизу вверх**: `payload.score < threshold` И `match.score >= threshold`, где `threshold = match.searchProfile.notification_threshold` (берём из загруженного профиля; для теста профиль доступен через relation).
4. Иначе → `false`.

> Терминальные значения `TenderSourceStatus`: `Completed`, `Cancelled` (сверено по енуму; `Active`, `Unknown` — нетерминальные).
> Снимок payload пишет `NotifyJob` (Task 4) — поля `price` (string|null), `deadline_at` (ISO-8601 строка|null), `source_status` (string), `score` (int). Decider читает их из `NotificationLog::$payload` (cast array).
> Сравнение цен через `bccomp`/float-арифметику: `abs(new-old)/old >= 0.10`. Берём `(float)` от numeric-string.

- [ ] **Write failing test**

Создать `tests/Feature/Notifications/NotificationDeciderTest.php`:

```php
<?php

use App\Enums\ActionRecommendation;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\RelevanceLevel;
use App\Enums\TenderSourceStatus;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Services\Notification\NotificationDecider;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Хелпер: создаёт компанию, профиль (threshold=60), закупку и матч (со score),
 * возвращает свежий матч с загруженными tender+searchProfile.
 */
function deciderFixture(array $tenderAttrs = [], int $score = 75, int $threshold = 60): TenderMatch
{
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create([
        'notification_threshold' => $threshold,
    ]);
    $tender = Tender::factory()->create(array_merge([
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_status' => TenderSourceStatus::Active,
        'owner_company_id' => null,
    ], $tenderAttrs));

    $match = TenderMatch::factory()->create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'score' => $score,
        'level' => RelevanceLevel::fromScore($score),
        'action_recommendation' => ActionRecommendation::ReviewDocs,
    ]);

    return TenderMatch::withoutTenancy()
        ->with(['tender', 'searchProfile'])
        ->findOrFail($match->id);
}

/** Хелпер: пишет прошлый sent-лог со снимком payload. */
function pastSentLog(TenderMatch $match, array $payload): NotificationLog
{
    return NotificationLog::create([
        'company_id' => $match->company_id,
        'tender_id' => $match->tender_id,
        'search_profile_id' => $match->search_profile_id,
        'channel' => NotificationChannel::Max,
        'recipient' => '123456',
        'status' => NotificationStatus::Sent,
        'payload' => $payload,
        'sent_at' => now(),
    ]);
}

function decider(): NotificationDecider
{
    return app(NotificationDecider::class);
}

it('первый раз (нет прошлого лога) → слать', function () {
    $match = deciderFixture();
    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('повтор без изменений → НЕ слать', function () {
    $match = deciderFixture();
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});

it('ре-триггер: цена выросла ≥10% → слать', function () {
    $match = deciderFixture(['price' => '1200000.00']); // +20%
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('ре-триггер: цена изменилась <10% → НЕ слать', function () {
    $match = deciderFixture(['price' => '1050000.00']); // +5%
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});

it('ре-триггер: изменился дедлайн → слать', function () {
    $match = deciderFixture(['deadline_at' => Carbon::parse('2026-07-10 18:00:00')]);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('ре-триггер: source_status стал терминальным (cancelled) → слать', function () {
    $match = deciderFixture(['source_status' => TenderSourceStatus::Cancelled]);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('source_status уже был терминальным (без перехода) → НЕ слать', function () {
    $match = deciderFixture(['source_status' => TenderSourceStatus::Completed]);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'completed',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});

it('ре-триггер: score пересёк порог снизу вверх → слать', function () {
    $match = deciderFixture(score: 65, threshold: 60); // текущий >= порог
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 55, // прошлый < порог
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('score был выше порога и остался → НЕ слать (нет пересечения снизу)', function () {
    $match = deciderFixture(score: 80, threshold: 60);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 70, // уже был >= порога
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});
```

- [ ] **Implement**

Создать `app/Services/Notification/NotificationDecider.php`:

```php
<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\TenderSourceStatus;
use App\Models\NotificationLog;
use App\Models\TenderMatch;

/**
 * Антидубль уведомлений (§9 спеки). Решает, слать ли уведомление по паре
 * (профиль × закупка). Первый раз — слать. Повтор — только при явном ре-триггере:
 * цена ±≥10%, изменился дедлайн, source_status стал терминальным, score пересёк
 * notification_threshold снизу вверх. Сравнение — со снимком payload прошлого
 * sent-уведомления (пишет NotifyJob). Канал: MAX.
 */
final class NotificationDecider
{
    private const PRICE_DELTA = 0.10;

    /** @var list<TenderSourceStatus> */
    private const TERMINAL = [TenderSourceStatus::Completed, TenderSourceStatus::Cancelled];

    public function shouldNotify(TenderMatch $match): bool
    {
        $last = NotificationLog::withoutTenancy()
            ->where('search_profile_id', $match->search_profile_id)
            ->where('tender_id', $match->tender_id)
            ->where('channel', NotificationChannel::Max)
            ->where('status', NotificationStatus::Sent)
            ->latest('id')
            ->first();

        if ($last === null) {
            return true;
        }

        /** @var array<string, mixed> $payload */
        $payload = $last->payload;
        $tender = $match->tender;

        return $this->priceChanged($tender->price, $payload['price'] ?? null)
            || $this->deadlineChanged($tender->deadline_at?->toIso8601String(), $payload['deadline_at'] ?? null)
            || $this->becameTerminal($tender->source_status, $payload['source_status'] ?? null)
            || $this->crossedThresholdUp($match, $payload['score'] ?? null);
    }

    private function priceChanged(?string $current, mixed $previous): bool
    {
        if ($current === null) {
            return false;
        }
        $new = (float) $current;
        $old = $previous === null ? 0.0 : (float) $previous;

        if ($old <= 0.0) {
            // прошлой цены не было (0/null), а сейчас задана — считаем изменением
            return $new > 0.0;
        }

        return abs($new - $old) / $old >= self::PRICE_DELTA;
    }

    private function deadlineChanged(?string $current, mixed $previous): bool
    {
        $prev = $previous === null ? null : (string) $previous;

        return $current !== $prev;
    }

    private function becameTerminal(TenderSourceStatus $current, mixed $previous): bool
    {
        $isTerminalNow = in_array($current, self::TERMINAL, true);
        if (! $isTerminalNow) {
            return false;
        }

        $prev = $previous === null ? null : TenderSourceStatus::tryFrom((string) $previous);
        $wasTerminal = $prev !== null && in_array($prev, self::TERMINAL, true);

        // триггер только при переходе нетерминальный → терминальный
        return ! $wasTerminal;
    }

    private function crossedThresholdUp(TenderMatch $match, mixed $previousScore): bool
    {
        $threshold = (int) $match->searchProfile->notification_threshold;
        $old = $previousScore === null ? 0 : (int) $previousScore;

        return $old < $threshold && $match->score >= $threshold;
    }
}
```

- [ ] `./vendor/bin/pint --test` + `phpstan analyse app/` (L6) проходят
- [ ] Коммит: `A7 C2 Task 3: NotificationDecider — антидубль §9 (первый/ре-триггеры цена·дедлайн·терминал·порог)` (trailer)

---

### Task 4: NotifyJob — отправка MAX + лог + notified_count++

**Files:**
- Create `app/Jobs/NotifyJob.php`
- Create `tests/Feature/Notifications/NotifyJobTest.php`

**Interfaces:**
- Consumes: `tenderMatchId` (int), `App\Notifications\MaxGateway` (биндинг C1 Task 1), `App\Services\Notification\NotificationDecider`, `App\Notifications\MaxNotificationPresenter`, `App\Notifications\FakeMaxGateway` (тесты).
- Produces: запись в `notification_logs` (`sent`/`failed`), инкремент `radar_runs.notified_count`, вызов `MaxGateway::sendMessage(chat_id, text)`.

**Поведение (§2.3 A7):**
- `tries=3`, `backoff`, `afterCommit()` (видеть зафиксированный match). Очередь и соединение — `$this->onConnection('notifications')->onQueue('notifications')` в конструкторе (НЕ типизированные свойства — урок A6; connection `notifications` определён в `config/queue.php` кластером C1, как redis-long/collect в A6). Защита от живого HTTP в тестах — глобальный bind `FakeMaxGateway` (Task 1, см. контракт №2), не зависит от поведения sync-очереди.
- `handle(MaxGateway $gateway, NotificationDecider $decider)`:
  1. Грузим `TenderMatch::withoutTenancy()->with(['tender.source','searchProfile'])->find($id)`; если нет — выход (мог быть удалён).
  2. `$company = Company::find($match->company_id)` (TenderMatch без связи `company()`); если `max_chat_id` пуст → `logFailed($match, null, 'no chat_id')` и выход.
  3. `$decider->shouldNotify($match) === false` → выход без лога (антидубль).
  4. `$text = MaxNotificationPresenter::render($match)`.
  5. `try { $gateway->sendMessage($chatId, $text, []); }` → `logSent($match, $chatId)` + `incrementNotified($match)`. `catch (Throwable $e) { logFailed($match, $chatId, $e->getMessage()); throw $e; }` (проброс → ретрай).
- Снимок payload в `logSent`: `{price, deadline_at(ISO), source_status(value), score}` — формат, который читает `NotificationDecider`.
- `incrementNotified`: `RadarRun::withoutTenancy()->whereKey($match->radar_run_id)->update(['notified_count' => DB::raw('notified_count + 1')])` (атомарно, паттерн `ProcessChunkJob`). Только если `radar_run_id` не null.
- Все записи в `notification_logs` — через `NotificationLog::create([... 'company_id' => $match->company_id ...])` (явный company_id; модель tenant-scoped, но в джобе TenantContext не задан).

> tenant-изоляция: джоб грузит match через `withoutTenancy()` по id, `chat_id` берётся ИЗ company этого матча — другой компании отправка невозможна (тест ниже).

- [ ] **Write failing test**

Создать `tests/Feature/Notifications/NotifyJobTest.php`:

```php
<?php

use App\Enums\ActionRecommendation;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\TenderSourceStatus;
use App\Jobs\NotifyJob;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Notifications\FakeMaxGateway;
use App\Notifications\MaxGateway;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
    $this->fakeGateway = new FakeMaxGateway;
    app()->instance(MaxGateway::class, $this->fakeGateway);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Создаёт компанию (+chat_id), профиль, закупку, radar_run и матч; возвращает матч.
 */
function notifyFixture(?string $chatId = '111222', int $score = 75): TenderMatch
{
    $company = Company::factory()->create(['max_chat_id' => $chatId]);
    $profile = SearchProfile::factory()->for($company)->create(['notification_threshold' => 60]);
    $tender = Tender::factory()->create([
        'title' => 'Поставка кранов',
        'price' => '1500000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_status' => TenderSourceStatus::Active,
        'owner_company_id' => null,
    ]);
    $run = RadarRun::create([
        'company_id' => $company->id,
        'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Ok,
        'started_at' => now(),
        'notified_count' => 0,
    ]);

    return TenderMatch::factory()->create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'radar_run_id' => $run->id,
        'score' => $score,
        'level' => RelevanceLevel::fromScore($score),
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'кран']],
        'risk_flags' => [],
    ]);
}

it('happy-path: отправляет, пишет sent-лог и инкрементит notified_count', function () {
    $match = notifyFixture();

    (new NotifyJob($match->id))->handle($this->fakeGateway, app(App\Services\Notification\NotificationDecider::class));

    expect($this->fakeGateway->sent)->toHaveCount(1)
        ->and($this->fakeGateway->sent[0]['chatId'])->toBe('111222')
        ->and($this->fakeGateway->sent[0]['text'])->toContain('Поставка кранов');

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->channel)->toBe(NotificationChannel::Max)
        ->and($log->recipient)->toBe('111222')
        ->and($log->company_id)->toBe($match->company_id)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->payload['price'])->toBe('1500000.00')
        ->and($log->payload['source_status'])->toBe('active')
        ->and($log->payload['score'])->toBe(75);

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(1);
});

it('нет max_chat_id → failed-лог, без отправки, notified_count не растёт', function () {
    $match = notifyFixture(chatId: null);

    (new NotifyJob($match->id))->handle($this->fakeGateway, app(App\Services\Notification\NotificationDecider::class));

    expect($this->fakeGateway->sent)->toBeEmpty();

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('chat_id');

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(0);
});

it('транспорт-ошибка → failed-лог и проброс исключения (ретрай)', function () {
    $match = notifyFixture();
    $this->fakeGateway->throwOnSend = new RuntimeException('max 500');

    $job = new NotifyJob($match->id);

    expect(fn () => $job->handle($this->fakeGateway, app(App\Services\Notification\NotificationDecider::class)))
        ->toThrow(RuntimeException::class, 'max 500');

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('max 500');

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(0);
});

it('антидубль: повтор без изменений → выход без отправки и без лога', function () {
    $match = notifyFixture();

    // первый раз — отправит и залогирует sent
    (new NotifyJob($match->id))->handle($this->fakeGateway, app(App\Services\Notification\NotificationDecider::class));
    expect(NotificationLog::withoutTenancy()->count())->toBe(1);

    // второй раз без изменений — Decider скажет «не слать»
    $fresh = new FakeMaxGateway;
    (new NotifyJob($match->id))->handle($fresh, app(App\Services\Notification\NotificationDecider::class));

    expect($fresh->sent)->toBeEmpty()
        ->and(NotificationLog::withoutTenancy()->count())->toBe(1); // новых логов нет
});

it('tenant-изоляция: джоб матча компании А шлёт на chat_id А, не Б', function () {
    $matchA = notifyFixture(chatId: 'AAA');
    $matchB = notifyFixture(chatId: 'BBB');

    (new NotifyJob($matchA->id))->handle($this->fakeGateway, app(App\Services\Notification\NotificationDecider::class));

    expect($this->fakeGateway->sent)->toHaveCount(1)
        ->and($this->fakeGateway->sent[0]['chatId'])->toBe('AAA');

    // Лог записан под компанию А, не Б
    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->company_id)->toBe($matchA->company_id)
        ->and($log->company_id)->not->toBe($matchB->company_id);
});
```

> Фейк `FakeMaxGateway` (Task 1): буфер `public array $sent` (ключи `chatId`/`text`/`opts`), ошибка транспорта моделируется через `public ?\Throwable $throwOnSend`.

- [ ] **Implement**

Создать `app/Jobs/NotifyJob.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\RadarRun;
use App\Models\TenderMatch;
use App\Notifications\MaxGateway;
use App\Notifications\MaxNotificationPresenter;
use App\Services\Notification\NotificationDecider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotifyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $tenderMatchId)
    {
        $this->onConnection('notifications')->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(MaxGateway $gateway, NotificationDecider $decider): void
    {
        /** @var TenderMatch|null $match */
        $match = TenderMatch::withoutTenancy()
            ->with(['tender.source', 'searchProfile'])
            ->find($this->tenderMatchId);

        if ($match === null) {
            return; // матч удалён между диспатчем и обработкой
        }

        // TenderMatch не имеет связи company() — грузим компанию явно
        // (Company без BelongsToCompany-trait, scope не применяется).
        $company = Company::find($match->company_id);
        $chatId = $company?->max_chat_id;
        if ($chatId === null || $chatId === '') {
            $this->logFailed($match, null, 'no chat_id');

            return;
        }

        if (! $decider->shouldNotify($match)) {
            return; // антидубль §9 — без лога-дубля
        }

        $text = MaxNotificationPresenter::render($match);

        try {
            $gateway->sendMessage((string) $chatId, $text, []);
        } catch (Throwable $e) {
            $this->logFailed($match, (string) $chatId, $e->getMessage());

            throw $e; // проброс → ретрай джоба
        }

        $this->logSent($match, (string) $chatId);
        $this->incrementNotified($match);
    }

    private function logSent(TenderMatch $match, string $chatId): void
    {
        $tender = $match->tender;

        NotificationLog::create([
            'company_id' => $match->company_id,
            'tender_id' => $match->tender_id,
            'search_profile_id' => $match->search_profile_id,
            'channel' => NotificationChannel::Max,
            'recipient' => $chatId,
            'status' => NotificationStatus::Sent,
            'payload' => [
                'price' => $tender->price,
                'deadline_at' => $tender->deadline_at?->toIso8601String(),
                'source_status' => $tender->source_status->value,
                'score' => $match->score,
            ],
            'sent_at' => now(),
        ]);
    }

    private function logFailed(TenderMatch $match, ?string $chatId, string $error): void
    {
        NotificationLog::create([
            'company_id' => $match->company_id,
            'tender_id' => $match->tender_id,
            'search_profile_id' => $match->search_profile_id,
            'channel' => NotificationChannel::Max,
            'recipient' => (string) ($chatId ?? ''),
            'status' => NotificationStatus::Failed,
            'payload' => [],
            'error' => mb_substr($error, 0, 1000),
        ]);
    }

    private function incrementNotified(TenderMatch $match): void
    {
        if ($match->radar_run_id === null) {
            return;
        }

        RadarRun::withoutTenancy()
            ->whereKey($match->radar_run_id)
            ->update(['notified_count' => DB::raw('notified_count + 1')]);
    }
}
```

> Заметка по компании: `TenderMatch` НЕ имеет связи `company()` (только `tender()`/`searchProfile()`), поэтому загружаем компанию явно через `Company::find($match->company_id)` (Company без `BelongsToCompany` — `find` работает без scope). Канонический код выше уже это делает — `with([...])` грузит только `tender.source` и `searchProfile`.

- [ ] `./vendor/bin/pint --test` + `phpstan analyse app/` (L6) проходят
- [ ] Коммит: `A7 C2 Task 4: NotifyJob — MAX-отправка, sent/failed-логи, notified_count++, антидубль, tenant-изоляция` (trailer)

---

### Task 5: Хук диспатча NotifyJob в MatchProfileService

**Files:**
- Modify `app/Scoring/MatchProfileService.php` (ТОЛЬКО точка после `persist`)
- Create `tests/Feature/Scoring/MatchProfileServiceNotifyTest.php` (новый файл — НЕ трогаем существующий `MatchProfileServiceTest.php`)

**Interfaces:**
- Consumes: результат `persist` (созданный/обновлённый `TenderMatch`), `profile.notification_threshold`, `result.score`.
- Produces: `NotifyJob::dispatch($matchId)` при `score >= threshold`.

**Решение по сигнатуре (минимально, без ломки) — ДВЕ локации правки:**
- **(a) Приватный `persist(...)`:** сейчас `void`. Меняем тип возврата `void`→`TenderMatch` и добавляем `return $match;` (замыкание `DB::transaction` тоже типизируется `: TenderMatch` и возвращает `$match`). Метод приватный — сигнатура не публичная, существующие тесты не зависят от типа возврата (вызывается изнутри).
- **(b) Точка вызова** — внутри замыкания `chunkById` (реальный файл, ~строки 90–91): `$match = $this->persist(...); $matched++;` затем `if ($result->score >= (int) $profile->notification_threshold) { NotifyJob::dispatch($match->id); }`.
- `notification_threshold` доступен прямо из `$profile` (передаётся в `run()` и захвачен в замыкание `chunkById` — уже есть в `use (..., $profile, ...)`).

Изменения в `app/Scoring/MatchProfileService.php`:

1. Добавить импорт:
```php
use App\Jobs\NotifyJob;
```

2. В замыкании `chunkById` заменить блок persist:
```php
                        $match = $this->persist($companyId, (int) $tender->id, (int) $profile->id, (int) $run->id, $result);
                        $matched++;

                        if ($result->score >= (int) $profile->notification_threshold) {
                            NotifyJob::dispatch($match->id);
                        }
```

3. Изменить сигнатуру и тело `persist` — вернуть созданный/обновлённый матч:
```php
    private function persist(int $companyId, int $tenderId, int $profileId, int $runId, ScoreResult $result): TenderMatch
    {
        return DB::transaction(function () use ($companyId, $tenderId, $profileId, $runId, $result): TenderMatch {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['ct:'.$companyId.':'.$tenderId]);

            $match = TenderMatch::withoutTenancy()->updateOrCreate(
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

            return $match;
        });
    }
```

> `DB::transaction` возвращает значение замыкания — id матча доступен после коммита. `NotifyJob::afterCommit()` гарантирует, что джоб увидит зафиксированный матч (важно: persist в транзакции; dispatch ВНЕ транзакции persist — `NotifyJob::dispatch` вызывается уже после возврата из `persist`, т.е. транзакция закоммичена).

- [ ] **Write failing test**

Создать `tests/Feature/Scoring/MatchProfileServiceNotifyTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Jobs\NotifyJob;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
    Source::factory()->create(['code' => 'manual']);
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function notifyProfile(Company $company, array $keywords, int $threshold): SearchProfile
{
    $profile = SearchProfile::factory()->for($company)->create([
        'is_active' => true,
        'notification_threshold' => $threshold,
    ]);
    foreach ($keywords as [$value, $type]) {
        Keyword::factory()->for($profile)->create(['value' => $value, 'type' => $type]);
    }

    return $profile->refresh();
}

function enableNotifySource(Company $company, Source $source): void
{
    CompanySource::withoutTenancy()->create([
        'company_id' => $company->id,
        'source_id' => $source->id,
        'is_active' => true,
    ]);
}

it('диспатчит NotifyJob, когда score >= notification_threshold', function () {
    $company = Company::factory()->create(['max_chat_id' => '123']);
    $source = Source::factory()->create();
    enableNotifySource($company, $source);

    // exact_in_title 30 + price_above_min 20 + priority_region 10 = 60 >= порог 60
    $profile = notifyProfile($company, [['краны мостовые', KeywordType::Exact]], threshold: 60);
    $profile->update(['min_price' => '1000000', 'regions' => ['Москва']]);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка: краны мостовые опорные',
        'price' => '2000000',
        'region' => 'Москва',
        'deadline_at' => now()->addDays(3),
        'owner_company_id' => null,
    ]);

    app(MatchProfileService::class)->run($profile->refresh(), RunTrigger::Import);

    $match = TenderMatch::withoutTenancy()->sole();
    expect($match->score)->toBe(60);

    Queue::assertPushed(NotifyJob::class, function (NotifyJob $job) use ($match) {
        return $job->tenderMatchId === $match->id;
    });
});

it('НЕ диспатчит NotifyJob, когда score < notification_threshold', function () {
    $company = Company::factory()->create(['max_chat_id' => '123']);
    $source = Source::factory()->create();
    enableNotifySource($company, $source);

    // keyword_in_title 20 < порог 60
    $profile = notifyProfile($company, [['кран', KeywordType::Main]], threshold: 60);

    Tender::factory()->create([
        'source_id' => $source->id,
        'title' => 'Поставка кранов',
        'deadline_at' => now()->addDays(3),
        'owner_company_id' => null,
    ]);

    app(MatchProfileService::class)->run($profile, RunTrigger::Import);

    expect(TenderMatch::withoutTenancy()->sole()->score)->toBe(20);
    Queue::assertNotPushed(NotifyJob::class);
});
```

> Тест опирается на детерминированные баллы из существующего `MatchProfileServiceTest` (exact 30 + price 20 + region 10 = 60; keyword_in_title = 20). Если фактический Scorer даст иные значения — выровнять `threshold`/ожидания при исполнении (ассерт по `$match->score` ловит расхождение явно).

- [ ] **Implement** изменения в `app/Scoring/MatchProfileService.php` (см. блоки 1–3 выше: импорт `NotifyJob`, точка вызова в замыкании chunkById, сигнатура+`return $match` в `persist`).
- [ ] Прогнать `./vendor/bin/pint app/Scoring/MatchProfileService.php` (repo-wide `pint --test` в CI — формат обязан совпасть после правок).
- [ ] Прогнать СУЩЕСТВУЮЩИЙ `tests/Feature/Scoring/MatchProfileServiceTest.php` — должен остаться ЗЕЛЁНЫМ. `persist` возвращает значение — поведение прежнее. Кейс `strong` даёт score=60 = дефолтный `notification_threshold`, поэтому при `QUEUE_CONNECTION=sync` (phpunit.xml) `NotifyJob` выполнился бы инлайн; живой HTTP исключён глобальным bind `FakeMaxGateway` из Task 1 (FIX-1, шаг 1.2a). Зависимость зафиксирована: Task 1 биндит фейк ДО Task 5.
- [ ] `./vendor/bin/pint --test` + `phpstan analyse app/` (L6) проходят
- [ ] Коммит: `A7 C2 Task 5: хук MatchProfileService → NotifyJob::dispatch при score>=threshold` (trailer)


---


# A7 — Кластер C3: ручной запуск радара + тестовое MAX-сообщение (Implementation Plan)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (или executing-plans). Шаги — чекбоксы (`- [ ]`). Источник истины — `docs/superpowers/specs/2026-06-18-a7-telegram-notifications-design.md` (§2.6, §2.7, §5). Стиль — `docs/superpowers/plans/2026-06-17-a6-eis-adapter.md`.

**Scope C3 (Task 6–7):** Дать пользователю с гейтом `manage-company` (1) запускать матчинг радара из UI — по одному направлению и по всей компании (`MatchProfileJob::dispatch(...)`); (2) задавать `max_chat_id` и слать тестовое MAX-сообщение через `App\Notifications\MaxGateway` с логом в `notification_logs`.

## Global Constraints (входят в КАЖДУЮ задачу C3)

- Laravel 13.14, PHP 8.3, PostgreSQL 16, Inertia/Vue 3. Тесты — **Pest** (`it('…', function () {…})`, `expect()`), Feature в `tests/Feature/**` (RefreshDatabase глобально через `tests/Pest.php`).
- CI на каждой задаче: `./vendor/bin/pint --test` (repo-wide, чисто) + `./vendor/bin/phpstan analyse` (larastan **L6 = 0** по `app/`) + `php artisan test` зелёный.
- Хелпер `adminWithPlan(int $maxProfiles = 3): User` (Admin + Company + активная подписка) — в `tests/Pest.php`. Гейт `manage-company` = `role === UserRole::Admin && company_id !== null` (`AppServiceProvider`). Не-Admin (Manager/Viewer) → 403; гость → redirect на `route('login')`.
- **Tenant-изоляция:** модели `SearchProfile`/`NotificationLog` используют trait `BelongsToCompany` — read-scope по `company_id` активного тенанта работает автоматически в HTTP-контексте (TenantContext заполнен из авторизованного пользователя). Запрос «все активные профили компании» = `SearchProfile::query()->where('is_active', true)` (scope сам ограничит компанией). `company_id` при `create` проставляется автоматически (`creating`-хук).
- **Цена/даты** в C3 не трогаем (это NotifyJob/Decider — кластеры C1/C2).
- **Контракт C1 (Consumes, НЕ создаём):** `App\Notifications\MaxGateway::sendMessage(string $chatId, string $text, array $opts = []): void`; биндинг интерфейса в `AppServiceProvider` и `App\Notifications\FakeMaxGateway` (для тестов) даёт кластер C1. C3 только потребляет интерфейс через DI/`app()`. В тестах C3 подменяем реализацию на Fake локально (`$this->app->instance(...)`), чтобы не зависеть от готовности C1 при ревью отдельной задачи.

## Владение и общие файлы

**Owned C3 (правит только C3):**
- `app/Http/Controllers/RadarRunController.php` (Task 6, новый).
- `app/Http/Controllers/CompanySettingsController.php` (Task 7).
- `app/Http/Requests/CompanySettingsRequest.php` (Task 7 — ДОБАВИТЬ правило `max_chat_id`, см. ниже).
- `resources/js/Pages/Profiles/Index.vue` (Task 6) и `resources/js/Pages/Profiles/Edit.vue` (Task 6 — per-profile кнопка опционально; основная — в Index).
- `resources/js/Pages/Company/Edit.vue` (Task 7).
- Тесты: `tests/Feature/Radar/RadarRunControllerTest.php` (Task 6), `tests/Feature/Company/CompanyTestMessageTest.php` (Task 7).

**ОБЩИЙ ФАЙЛ — `routes/web.php` (совместно с кластером C4 / дашборд).** Правится ТОЛЬКО ADDITIVE/SURGICAL: добавляем строки внутри существующей группы `Route::middleware(['auth', 'can:manage-company'])->group(...)` и/или новую `manage-company`-группу-якорь перед `require __DIR__.'/auth.php';`. **НЕ переписывать файл целиком, НЕ трогать closure `/dashboard` (его заменяет C4), НЕ трогать группы `view-tenders`/`superadmin`.**

**НЕ трогать (чужое владение):** `config/*`, `app/Providers/AppServiceProvider.php`, `app/Scoring/MatchProfileService.php`, `app/Http/Controllers/DashboardController.php`, `resources/js/Pages/Dashboard.vue`, `app/Notifications/*` (C1), `app/Jobs/NotifyJob.php` (C1/C2).

## Сверенные факты кода (на момент планирования)

- `MatchProfileJob.__construct(public int $searchProfileId, public string $trigger, public ?string $sinceUpdatedAt = null)` — публичные поля. `RunTrigger::Manual->value === 'manual'`.
- `CompanySettingsRequest::rules()` содержит легаси-правило `telegram_chat_id` (остаток Telegram-плана, НЕ трогать). Поля/правила `max_chat_id` в коде НЕТ — это **НОВОЕ поле этапа A7**. **Task 7 ДОБАВЛЯЕТ** рядом правило `'max_chat_id' => ['nullable', 'string', 'max:64']`, маршрут/метод/кнопку тестового сообщения и поле формы.
- `companies.max_chat_id` (миграция — добавлена в C1) и `companies.notification_email` — есть. `Company $guarded = ['id']` → `update(['max_chat_id' => ...])` работает.
- `resources/js/Pages/Company/Edit.vue` выводит легаси-поле `telegram_chat_id` (НЕ трогать). Поля `max_chat_id` во Vue-форме ещё НЕТ. Task 7 ДОБАВЛЯЕТ рядом новое поле `max_chat_id` (`form.max_chat_id`) + кнопку «Отправить тестовое».
- `notification_logs`: `recipient` — `string NOT NULL`; `tender_id`/`search_profile_id` — nullable FK; `channel`/`status` — string-касты на enum; `payload` — jsonb default `{}`; `sent_at` nullable. Модель `App\Models\NotificationLog` (`use BelongsToCompany`, `$guarded=['id']`). Enum `NotificationChannel::Max`, `NotificationStatus::{Sent,Failed}`.
- `CompanySettingsController` сейчас: `edit(Request)` → `Inertia::render('Company/Edit', ['company' => $request->user()->company])`; `update(CompanySettingsRequest)` → `back()->with('success', …)`.
- Паттерн триггер-экшена (эталон `Admin/SourceCollectController::trigger`): `$request->user()->can('…') || abort(403)` (или гейт в группе маршрута) → `Job::dispatch(...)` → `redirect()->back()->with('success', …)`.
- Flash-баннер `AuthenticatedLayout.vue` читает `flash.success`/`flash.error` (зелёный/красный). Inertia-share прокидывает `auth.can.manageCompany`.

---

### Task 6: Ручной запуск радара из UI (per-направление + по компании)

**Files:**
- Create `app/Http/Controllers/RadarRunController.php`
- Modify `routes/web.php` (ADDITIVE/surgical — 2 POST в `manage-company`-группе; ОБЩИЙ с C4)
- Modify `resources/js/Pages/Profiles/Index.vue` (кнопка «Запустить радар» по компании + per-строка)
- Create `tests/Feature/Radar/RadarRunControllerTest.php`

**Interfaces:**
- Consumes: `App\Jobs\MatchProfileJob` (`dispatch(int, string, ?string)`), `App\Enums\RunTrigger::Manual`, `App\Models\SearchProfile` (scoped), гейт `manage-company`.
- Produces: маршруты `profiles.run` (`POST profiles/{profile}/run`) и `profiles.run-company` (`POST profiles/run-company`); flash `success`.

---

- [ ] **Write failing test** — создать `tests/Feature/Radar/RadarRunControllerTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Jobs\MatchProfileJob;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

function activeProfileForCompany(int $companyId): SearchProfile
{
    $profile = SearchProfile::withoutTenancy()->create([
        'company_id' => $companyId,
        'name' => 'Металлоконструкции '.uniqid(),
        'is_active' => true,
        'notification_threshold' => 60,
        'deadline_min_days' => 0,
        'exclude_on_negative_in_title' => false,
    ]);

    Keyword::withoutTenancy()->create([
        'company_id' => $companyId,
        'search_profile_id' => $profile->id,
        'type' => KeywordType::Main->value,
        'value' => 'ферма',
    ]);

    return $profile;
}

it('manage-company пользователь запускает радар по одному направлению', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $profile = activeProfileForCompany($admin->company_id);

    $this->actingAs($admin)
        ->post(route('profiles.run', $profile))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(MatchProfileJob::class, function (MatchProfileJob $job) use ($profile) {
        return $job->searchProfileId === $profile->id
            && $job->trigger === RunTrigger::Manual->value
            && $job->sinceUpdatedAt === null;
    });
});

it('manage-company пользователь запускает радар по всей компании — только активные профили', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $active1 = activeProfileForCompany($admin->company_id);
    $active2 = activeProfileForCompany($admin->company_id);

    $inactive = SearchProfile::withoutTenancy()->create([
        'company_id' => $admin->company_id,
        'name' => 'Выключено '.uniqid(),
        'is_active' => false,
        'notification_threshold' => 60,
        'deadline_min_days' => 0,
        'exclude_on_negative_in_title' => false,
    ]);

    $this->actingAs($admin)
        ->post(route('profiles.run-company'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(MatchProfileJob::class, 2);
    Bus::assertDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $active1->id);
    Bus::assertDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $active2->id);
    Bus::assertNotDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $inactive->id);
});

it('запуск по компании не диспатчит профили чужой компании (tenant-изоляция)', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $mine = activeProfileForCompany($admin->company_id);

    $other = adminWithPlan();
    $foreign = activeProfileForCompany($other->company_id);

    $this->actingAs($admin)
        ->post(route('profiles.run-company'))
        ->assertRedirect();

    Bus::assertDispatched(MatchProfileJob::class, 1);
    Bus::assertDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $mine->id);
    Bus::assertNotDispatched(MatchProfileJob::class, fn (MatchProfileJob $j) => $j->searchProfileId === $foreign->id);
});

it('per-profile: нельзя запустить чужое направление — 404 (scope скрывает модель)', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $other = adminWithPlan();
    $foreign = activeProfileForCompany($other->company_id);

    $this->actingAs($admin)
        ->post(route('profiles.run', $foreign))
        ->assertNotFound();

    Bus::assertNothingDispatched();
});

it('не-manage-company (менеджер) получает 403 на оба маршрута', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);
    $profile = activeProfileForCompany($admin->company_id);

    $this->actingAs($manager)->post(route('profiles.run', $profile))->assertForbidden();
    $this->actingAs($manager)->post(route('profiles.run-company'))->assertForbidden();

    Bus::assertNothingDispatched();
});

it('гость получает редирект на login', function () {
    Bus::fake();
    $admin = adminWithPlan();
    $profile = activeProfileForCompany($admin->company_id);

    $this->post(route('profiles.run', $profile))->assertRedirect(route('login'));
    $this->post(route('profiles.run-company'))->assertRedirect(route('login'));

    Bus::assertNothingDispatched();
});
```

- [ ] **Run** (Expected FAIL — контроллер и маршруты отсутствуют)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=RadarRunControllerTest
```

```
Error: Route [profiles.run] not defined.
Tests:  6 failed
```

- [ ] **Implement** — создать `app/Http/Controllers/RadarRunController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\RunTrigger;
use App\Jobs\MatchProfileJob;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;

class RadarRunController extends Controller
{
    /** Запуск матчинга по одному направлению текущей компании. */
    public function trigger(SearchProfile $profile): RedirectResponse
    {
        MatchProfileJob::dispatch($profile->id, RunTrigger::Manual->value, null);

        return redirect()->back()->with('success', "Радар по направлению «{$profile->name}» запущен.");
    }

    /** Запуск матчинга по всем активным направлениям текущей компании. */
    public function triggerCompany(): RedirectResponse
    {
        $ids = SearchProfile::query()
            ->where('is_active', true)
            ->pluck('id');

        foreach ($ids as $id) {
            MatchProfileJob::dispatch($id, RunTrigger::Manual->value, null);
        }

        $count = $ids->count();

        return redirect()->back()->with(
            'success',
            $count > 0
                ? "Радар запущен по {$count} активным направлениям."
                : 'Нет активных направлений для запуска радара.'
        );
    }
}
```

> Примечание по tenant-изоляции: `SearchProfile` (trait `BelongsToCompany`) под route-model-binding и в `query()` автоматически ограничен `company_id` активного тенанта — чужое направление даёт 404 на биндинге, а `triggerCompany` берёт только профили своей компании. Явный `where('company_id', ...)` не нужен в HTTP-контексте (в отличие от очередей).

- [ ] **Implement** — `routes/web.php` (ОБЩИЙ с C4; ADDITIVE/surgical). Добавить use-импорт контроллера в начало блока `use`:

```php
use App\Http\Controllers\RadarRunController;
```

Внутри СУЩЕСТВУЮЩЕЙ группы `Route::middleware(['auth', 'can:manage-company'])->group(function () { … });` (та, где `profiles`-resource), добавить две строки рядом с `profiles.activate`/`profiles.deactivate`:

```php
    Route::post('profiles/run-company', [RadarRunController::class, 'triggerCompany'])->name('profiles.run-company');
    Route::post('profiles/{profile}/run', [RadarRunController::class, 'trigger'])->name('profiles.run');
```

> **Порядок важен:** статический `profiles/run-company` объявить ДО параметрического `profiles/{profile}/run`, иначе `run-company` поймается как `{profile}`. Альтернатива — оставить как есть, т.к. RMB по `run-company` не найдёт SearchProfile и даст 404; но безопаснее статику выше.
> **Якорь на случай отдельной группы:** если C4 ещё не добавил свои `manage-company`-маршруты конфликтно — допускается вставить эти 2 строки в существующую группу. НИЧЕГО за пределами группы не менять; `require __DIR__.'/auth.php';` остаётся последней строкой.

- [ ] **Implement** — `resources/js/Pages/Profiles/Index.vue`. Добавить в `<script setup>` обработчики (после `destroy`):

```js
const runProfile = (profile) => {
    router.post(route('profiles.run', profile.id), {}, { preserveScroll: true });
};

const runCompany = () => {
    router.post(route('profiles.run-company'), {}, { preserveScroll: true });
};
```

В шапке (`template #header`), рядом с кнопкой «Добавить направление», добавить кнопку «Запустить радар» по компании:

```vue
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 transition hover:bg-indigo-100"
                        @click="runCompany"
                    >
                        Запустить радар
                    </button>
                    <Link :href="route('profiles.create')">
                        <PrimaryButton>Добавить направление</PrimaryButton>
                    </Link>
                </div>
```

> Этот блок ЗАМЕНЯЕТ существующий `<Link :href="route('profiles.create')">…</Link>` внутри `<div class="flex items-center justify-between">` (оборачиваем кнопку «Добавить» и новую кнопку в общий контейнер `gap-3`).

В строке таблицы (`<td class="px-6 py-4 text-right">`) добавить per-направление кнопку перед «Удалить»:

```vue
                                <td class="px-6 py-4 text-right">
                                    <button
                                        class="mr-4 text-sm text-indigo-600 hover:underline"
                                        @click="runProfile(profile)"
                                    >
                                        Запустить
                                    </button>
                                    <button class="text-sm text-red-600 hover:underline" @click="destroy(profile)">
                                        Удалить
                                    </button>
                                </td>
```

> `router` уже импортирован (`import { Head, Link, router } from '@inertiajs/vue3';`) — дополнительный импорт не нужен.

- [ ] **Run** (Expected PASS)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=RadarRunControllerTest \
  && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add -A && git commit -m "A7 C3 Task 6: ручной запуск радара из UI (per-профиль + по компании)

RadarRunController::trigger/triggerCompany → MatchProfileJob::dispatch(Manual).
Маршруты profiles.run / profiles.run-company в группе manage-company (routes/web.php,
ADDITIVE; общий с C4). Кнопки «Запустить радар» в Profiles/Index. Feature-тесты:
гейт manage-company, tenant-изоляция, гость→login, чужой профиль→404.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Настройки MAX — тестовое сообщение

**Files:**
- Modify `app/Http/Controllers/CompanySettingsController.php` (+ метод `sendTestMessage`)
- Modify `app/Http/Requests/CompanySettingsRequest.php` (ДОБАВИТЬ правило `max_chat_id`; см. шаг ниже)
- Modify `routes/web.php` (ADDITIVE/surgical — 1 POST в `manage-company`-группе; ОБЩИЙ с C4)
- Modify `resources/js/Pages/Company/Edit.vue` (поле `max_chat_id` + кнопка «Отправить тестовое»)
- Create `tests/Feature/Company/CompanyTestMessageTest.php`

**Interfaces:**
- Consumes: `App\Notifications\MaxGateway::sendMessage(string, string, array)` (контракт C1; в тестах — `FakeMaxGateway` через `$this->app->instance(...)`), `App\Models\Company->max_chat_id`, `App\Models\NotificationLog`, enum `NotificationChannel::Max`/`NotificationStatus`, гейт `manage-company`.
- Produces: маршрут `company.test-message` (`POST company/test-message`); flash `success`/`error`; запись в `notification_logs`.

---

> **ВАЖНО (ADDITIVE):** `max_chat_id` — НОВОЕ поле этапа A7. Колонка `companies.max_chat_id` добавляется миграцией C1; правило валидации и поле Vue-формы ещё НЕ существуют (в коде только легаси `telegram_chat_id`). Task 7 ДОБАВЛЯЕТ: правило `'max_chat_id' => ['nullable', 'string', 'max:64']` в `CompanySettingsRequest::rules()`, поле `max_chat_id` в `defineProps`/`useForm` и блок поля в `Company/Edit.vue`, кнопку тестового сообщения, контроллер-метод, маршрут и тест. Легаси `telegram_chat_id` НЕ трогаем.

- [ ] **Write failing test** — создать `tests/Feature/Company/CompanyTestMessageTest.php`:

```php
<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\FakeMaxGateway;
use App\Notifications\MaxGateway;

beforeEach(function () {
    $this->fakeGateway = new FakeMaxGateway;
    $this->app->instance(MaxGateway::class, $this->fakeGateway);
});

it('отправляет тестовое сообщение через шлюз и пишет лог sent', function () {
    $admin = adminWithPlan();
    $admin->company->update(['max_chat_id' => '-100500']);

    $this->actingAs($admin)
        ->post(route('company.test-message'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->fakeGateway->sent)->toHaveCount(1)
        ->and($this->fakeGateway->sent[0]['chatId'])->toBe('-100500')
        ->and($this->fakeGateway->sent[0]['text'])->toContain('Тендерный радар');

    $log = NotificationLog::withoutTenancy()->where('company_id', $admin->company_id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->channel)->toBe(NotificationChannel::Max)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->recipient)->toBe('-100500');
});

it('пустой max_chat_id — flash-ошибка, без отправки и без лога', function () {
    $admin = adminWithPlan();
    $admin->company->update(['max_chat_id' => null]);

    $this->actingAs($admin)
        ->post(route('company.test-message'))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($this->fakeGateway->sent)->toHaveCount(0);
    expect(NotificationLog::withoutTenancy()->where('company_id', $admin->company_id)->count())->toBe(0);
});

it('ошибка транспорта — flash-ошибка и лог failed', function () {
    $admin = adminWithPlan();
    $admin->company->update(['max_chat_id' => '-100500']);

    $this->app->instance(MaxGateway::class, new class implements MaxGateway
    {
        public function sendMessage(string $chatId, string $text, array $opts = []): void
        {
            throw new \RuntimeException('max down');
        }
    });

    $this->actingAs($admin)
        ->post(route('company.test-message'))
        ->assertRedirect()
        ->assertSessionHas('error');

    $log = NotificationLog::withoutTenancy()->where('company_id', $admin->company_id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('max down');
});

it('менеджеру запрещено отправлять тестовое — 403', function () {
    $admin = adminWithPlan();
    $admin->company->update(['max_chat_id' => '-100500']);
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);

    $this->actingAs($manager)->post(route('company.test-message'))->assertForbidden();

    expect($this->fakeGateway->sent)->toHaveCount(0);
});

it('гость — редирект на login', function () {
    $this->post(route('company.test-message'))->assertRedirect(route('login'));
    expect($this->fakeGateway->sent)->toHaveCount(0);
});
```

> Тест зависит от контракта C1 (`App\Notifications\MaxGateway` + `FakeMaxGateway` с публичным `array $sent`). Если C1 ещё не влит на момент исполнения Task 7 — это нормально для subagent-driven: задача стартует после C1 (зависимость зафиксирована в master-плане A7). До готовности C1 тест падает с «class not found» — это ожидаемо.

- [ ] **Run** (Expected FAIL — маршрут/метод отсутствуют; при отсутствии C1 — class not found)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=CompanyTestMessageTest
```

- [ ] **Implement** — `app/Http/Controllers/CompanySettingsController.php` (добавить метод + импорты). Полный файл:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Requests\CompanySettingsRequest;
use App\Models\NotificationLog;
use App\Notifications\MaxGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Company/Edit', [
            'company' => $request->user()->company,
        ]);
    }

    public function update(CompanySettingsRequest $request): RedirectResponse
    {
        $request->user()->company->update($request->validated());

        return back()->with('success', 'Настройки компании сохранены.');
    }

    public function sendTestMessage(Request $request, MaxGateway $gateway): RedirectResponse
    {
        Gate::authorize('manage-company');

        $company = $request->user()->company;
        $chatId = (string) ($company->max_chat_id ?? '');

        if ($chatId === '') {
            return back()->with('error', 'Укажите MAX chat_id перед отправкой тестового сообщения.');
        }

        $text = 'Тестовое сообщение «Тендерный радар». Канал MAX подключён.';

        try {
            $gateway->sendMessage($chatId, $text);
        } catch (\Throwable $e) {
            NotificationLog::create([
                'company_id' => $company->id,
                'channel' => NotificationChannel::Max,
                'recipient' => $chatId,
                'status' => NotificationStatus::Failed,
                'payload' => ['text' => $text, 'kind' => 'test'],
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Не удалось отправить тестовое сообщение. Проверьте chat_id и настройки бота.');
        }

        NotificationLog::create([
            'company_id' => $company->id,
            'channel' => NotificationChannel::Max,
            'recipient' => $chatId,
            'status' => NotificationStatus::Sent,
            'payload' => ['text' => $text, 'kind' => 'test'],
            'sent_at' => now(),
        ]);

        return back()->with('success', 'Тестовое сообщение отправлено в MAX.');
    }
}
```

> Гейт продублирован `Gate::authorize('manage-company')` внутри метода как defense-in-depth (основная защита — middleware группы маршрута). `NotificationLog` под trait `BelongsToCompany` проставит `company_id` сам, но передаём явно для надёжности (insert/create обходят read-scope, но `creating`-хук работает; явный `company_id` детерминирует тест tenant-изоляции).

- [ ] **Implement** — `app/Http/Requests/CompanySettingsRequest.php`: ДОБАВИТЬ ключ `max_chat_id` в `rules()` рядом с легаси `telegram_chat_id` (легаси-строку НЕ удалять). После строки `'telegram_chat_id' => ['nullable', 'string', 'max:64'],`:

```php
            'max_chat_id' => ['nullable', 'string', 'max:64'],
```

Подтвердить дифф:

```bash
cd /home/roman/sites/www/tender && grep -n "max_chat_id" app/Http/Requests/CompanySettingsRequest.php
# ожидается: 'max_chat_id' => ['nullable', 'string', 'max:64'],
```

- [ ] **Implement** — `routes/web.php` (ОБЩИЙ с C4; ADDITIVE/surgical). Внутри той же группы `Route::middleware(['auth', 'can:manage-company'])->group(...)`, рядом со строками `company.edit`/`company.update`, добавить:

```php
    Route::post('company/test-message', [CompanySettingsController::class, 'sendTestMessage'])->name('company.test-message');
```

> `CompanySettingsController` уже импортирован в `routes/web.php`. Ничего за пределами группы не менять.

- [ ] **Implement** — `resources/js/Pages/Company/Edit.vue`. ADDITIVE-правки (легаси `telegram_chat_id` НЕ трогать):

1. В существующий импорт добавить `router`: `import { Head, useForm, router } from '@inertiajs/vue3';` (строка 8; `router` оттуда же — без отдельной строки импорта, чтобы не дублировать импорт пакета).

2. В `useForm({...})` добавить новое поле `max_chat_id` рядом с легаси `telegram_chat_id` (строка 21):

```js
    max_chat_id: props.company.max_chat_id ?? '',
```

3. Добавить обработчик в `<script setup>` (после `useForm`):

```js
const sendTestMessage = () => {
    router.post(route('company.test-message'), {}, { preserveScroll: true });
};
```

4. В шаблоне ДОБАВИТЬ новый `<div>` поля `max_chat_id` рядом с легаси-блоком `telegram_chat_id` (после `<div>` поля `telegram_chat_id`, строки 88–92; НЕ заменять легаси-блок). Внутри grid `sm:grid-cols-2`:

```vue
                        <div>
                            <InputLabel for="max_chat_id" value="MAX chat_id" />
                            <TextInput id="max_chat_id" v-model="form.max_chat_id" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.max_chat_id" />
                            <button
                                type="button"
                                class="mt-2 text-sm text-indigo-600 hover:underline disabled:opacity-50"
                                :disabled="!form.max_chat_id"
                                @click="sendTestMessage"
                            >
                                Отправить тестовое
                            </button>
                        </div>
```

> Это НОВЫЙ блок поля `max_chat_id` (легаси `telegram_chat_id` остаётся рядом). Кнопка `type="button"` — чтобы не сабмитить форму компании. Дизейбл при пустом chat_id дублирует серверную flash-ошибку (UX).

- [ ] **Run** (Expected PASS — при наличии C1)

```bash
cd /home/roman/sites/www/tender && php artisan test --filter=CompanyTestMessageTest \
  && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
```

- [ ] **Commit**

```bash
cd /home/roman/sites/www/tender && git add -A && git commit -m "A7 C3 Task 7: тестовое MAX-сообщение из настроек компании

CompanySettingsController::sendTestMessage → MaxGateway::sendMessage +
notification_logs (sent/failed); пустой max_chat_id → flash-ошибка без отправки.
Маршрут company.test-message в группе manage-company (routes/web.php, ADDITIVE;
общий с C4). Новое поле max_chat_id (правило в CompanySettingsRequest + form/props
в Company/Edit, лейбл «MAX chat_id») + кнопка «Отправить тестовое». Feature-тесты
на FakeMaxGateway: sent-лог, ошибка транспорта→failed, гейт, гость→login.
Колонка companies.max_chat_id — из C1; легаси telegram_chat_id не трогаем.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Зависимости и порядок исполнения C3

- **Task 6** не зависит от C1 — можно делать сразу (использует только `MatchProfileJob`, готовый из A4).
- **Task 7** зависит от контракта C1 (`App\Notifications\MaxGateway` + `FakeMaxGateway`). Исполнять ПОСЛЕ влития C1 (или с локальным стабом интерфейса в тесте, если ревью требует независимости — но боевой биндинг — за C1).

## Риски

1. **`routes/web.php` — общий с C4 (дашборд).** Возможен merge-конфликт, если C4 правит ту же `manage-company`-группу или импорты. Митигация: вставка строго ADDITIVE внутри существующей группы, без переписывания файла; `require __DIR__.'/auth.php';` неприкосновенен; closure `/dashboard` не трогаем (его на контроллер меняет C4).
2. **Порядок маршрутов** `profiles/run-company` (статический) vs `profiles/{profile}/run` (параметрический): статический объявить выше.
3. **Зависимость Task 7 ↔ C1.** До влития C1 тест Task 7 не пройдёт (class not found) — это ожидаемо; синхронизировать порядок в master-плане A7.
4. **`max_chat_id` добавляется миграцией C1** (новая колонка, не была в A1–A6) — убедиться, что миграция C1 применена до выполнения Task 7.


---


# A7 — Кластер C4: Дашборд (Tasks 8–9)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (рекомендуется) или superpowers:executing-plans — исполнять задачи по одной. Шаги — чекбоксы (`- [ ]`).

**Goal C4:** Превратить заглушку `/dashboard` в боевой company-scoped дашборд (§13.1 мастер-спеки / §2.8 дизайн-спеки A7): 7 элементов — счётчики новых/релевантных за 30 дней, блок высокоприоритетных закупок, ближайшие дедлайны, последние запуски (радар + сбор) с ошибками источников, график динамики найденных. Контроллер собирает props (tenant-scoped), Vue рендерит секции; график — inline-SVG bar-chart (без новой npm-зависимости).

## Источник истины

- Дизайн A7: `docs/superpowers/specs/2026-06-18-a7-telegram-notifications-design.md` §2.8 (имя файла оставлено прежним — §0 спеки: «чтобы не ломать ссылки плана/роадмапа»; содержимое переориентировано на MAX).
- Мастер-спека: `docs/superpowers/specs/2026-06-05-tender-radar-design.md` §13.1 (7 элементов), §13 (tenant-изоляция в HTTP — обязательный тест).

## Подтверждённые реальные имена (сверено по моделям/миграциям — НЕ менять)

- `company_tenders` (модель `App\Models\CompanyTender`, `use BelongsToCompany` → авто-скоуп по `company_id`):
  `best_score` (smallint, cast `integer`), `best_level` (string, cast `RelevanceLevel`), `first_matched_at` (`timestamp NOT NULL`, cast `datetime`), `status` (cast `WorkflowStatus`), связь `tender(): BelongsTo<Tender>`.
- `tenders` (модель `App\Models\Tender`, **глобальная, БЕЗ BelongsToCompany**): `deadline_at` (nullable `timestamp`, cast `datetime`), `price` (`decimal:2` → numeric-string), `title`, `region`, `customer_name`, `source_status` (cast `TenderSourceStatus`). Доступ компании к закупкам — ТОЛЬКО через `company_tenders` (join/whereHas), напрямую `Tender::query()` company-scope не даёт.
- `radar_runs` (модель `App\Models\RadarRun`, `use BelongsToCompany` → авто-скоуп): `processed_count`, `matched_count`, `notified_count`, `error_count`, `started_at`, `finished_at`, `status` (`RunStatus`), `trigger` (`RunTrigger`), `search_profile_id`. Связь `searchProfile(): BelongsTo<SearchProfile>`.
- `source_collect_runs` (модель `App\Models\SourceCollectRun`, **БЕЗ BelongsToCompany** — фильтровать `company_id` ЯВНО): `company_id` (nullable; ЕИС-раны = NULL), `error_count`, `log` (nullable text), `fetched_count`, `new_count`, `started_at`, `status`, `trigger`, `source_id`. Связь `source(): BelongsTo<Source>`.
- Enum `App\Enums\RelevanceLevel`: case `High = 'high'`; метод `->value === 'high'`.
- `App\Tenancy\BelongsToCompany`: global scope `company` активен, когда `TenantContext::id() !== null`. В HTTP контекст ставит middleware `App\Http\Middleware\SetTenantContext` (`->set($request->user()?->company_id)`). Суперадмин → `company_id === null` → scope НЕ применяется (вернёт всё) — поэтому для суперадмина дашборд company-scoped рендерим пустым (как `RunController`).

## Global Constraints (входят в каждую задачу C4)

- PHP 8.3, Laravel 13.14, PostgreSQL 16, Inertia/Vue 3. `./vendor/bin/pint --test` (repo-wide) и `./vendor/bin/phpstan analyse` (larastan **L6 = 0**) для `app/` — чисто. Все тесты зелёные: `php artisan test`.
- Тесты — **Pest**: `it('…', function () { … })`, `expect()`/`assertInertia`. Feature-тесты в `tests/Feature/**` (глобальный `RefreshDatabase` из `tests/Pest.php`). Хелпер `adminWithPlan(int $maxProfiles = 3): User`, `actingAs($user)`, `Inertia\Testing\AssertableInertia as Assert`.
- **Tenant-изоляция:** company-scoped модели (`CompanyTender`, `RadarRun`) читаем через авто-scope (контекст ставит middleware). Модели без scope (`SourceCollectRun`, `Tender`) фильтруем `company_id`/join по `company_tenders` ЯВНО. Tenant-изоляция дашборда — ОБЯЗАТЕЛЬНЫЙ тест (§13).
- **Цена** — `decimal:2` → строка; в props отдаём как есть (Vue форматирует через `Number(v)`), сравнения/математику на стороне PHP не делаем.
- **Даты** — app timezone UTC; в props уходят ISO-строки (Carbon сериализуется Inertia автоматически), Vue форматирует через `toLocaleDateString('ru-RU')`/`toLocaleString('ru-RU')`.
- **Каждая задача — отдельный коммит**, trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## Владение C4 (только этот кластер)

- `app/Http/Controllers/DashboardController.php` (создаётся, Task 8).
- `resources/js/Pages/Dashboard.vue` (замена заглушки, Task 9).
- `routes/web.php` — **ADDITIVE/SURGICAL** (Task 8): замена ОДНОЙ closure `/dashboard` на контроллер. **Файл ОБЩИЙ с кластером C3** — править только указанный якорь, не воспроизводить файл целиком, не трогать другие маршруты.
- НЕ трогать: `config/*`, `app/Providers/AppServiceProvider.php`, `MatchProfileService`, контроллеры/Vue кластера C3.

## Параметры дашборда (константы контроллера)

- Окно динамики/счётчиков: **30 дней** (`$windowDays = 30`) — счётчики и график используют ОДНО окно (дизайн §2.8 п.1/п.5).
- Топ высокоприоритетных: **N = 10** (`$highPriorityLimit`).
- Ближайшие дедлайны: **N = 10** (`$deadlinesLimit`).
- Последние запуски: **N = 10** на каждый тип (`$runsLimit`).

---

### Task 8: `DashboardController::index` — company-scoped props (§13.1)

**Files:**
- Create `app/Http/Controllers/DashboardController.php`
- Modify `routes/web.php` (SURGICAL — замена closure `/dashboard`; **общий с C3**)
- Create `tests/Feature/Dashboard/DashboardTest.php`

**Interfaces:**
- Produces: `Inertia::render('Dashboard', [...])` с props `counters`, `highPriority`, `upcomingDeadlines`, `recentRuns`, `dynamics` — потребляет `resources/js/Pages/Dashboard.vue` (Task 9).
- Consumes: `App\Models\{CompanyTender,Tender,RadarRun,SourceCollectRun}`, `App\Enums\RelevanceLevel`, авто-scope `BelongsToCompany` (контекст из middleware `SetTenantContext`).

**Props (точный контракт для Task 9):**
- `counters: { new: int, relevant: int }` — за окно 30 дней по `company_tenders.first_matched_at`. `new` = все company_tenders за окно; `relevant` = с `best_level` ∈ {high, medium} (релевантные — порог «средняя и выше», совпадает с порогами `RelevanceLevel::fromScore` ≥60).
- `highPriority: array<{ id, best_score, best_level, tender: { id, title, region, price, deadline_at } }>` — `best_level = high`, топ N по `best_score` desc.
- `upcomingDeadlines: array<{ id, tender: { id, title, region, price, deadline_at } }>` — company_tenders компании, у чьих tender `deadline_at` в будущем, ASC по `deadline_at`, топ N.
- `recentRuns: { radar: array<...>, collect: array<...> }` — последние N radar_runs (scoped) и source_collect_runs (явный `company_id`), отсортированы по `started_at` desc; в collect отдаём `error_count` (для пометки «ошибки источников» в UI).
- `dynamics: array<{ date: 'Y-m-d', count: int }>` — агрегат company_tenders по `date(first_matched_at)` за 30 дней, **с заполнением нулями** для дней без данных (для ровного графика).

#### Шаги

- [ ] **Write failing test** — `tests/Feature/Dashboard/DashboardTest.php`:

```php
<?php

use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Models\CompanyTender;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('гость не имеет доступа к дашборду', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('дашборд отдаёт все props company-scoped', function () {
    $admin = adminWithPlan();
    $companyId = $admin->company_id;

    // 2 закупки: одна high (приоритет + динамика), одна medium (релевантная, не high).
    $highTender = Tender::factory()->create([
        'title' => 'Высокий приоритет',
        'region' => 'Москва',
        'price' => '1000000.00',
        'deadline_at' => now()->addDays(5),
    ]);
    $mediumTender = Tender::factory()->create([
        'deadline_at' => now()->addDays(20),
    ]);
    // Закупка с истёкшим дедлайном — НЕ должна попасть в upcomingDeadlines.
    $expiredTender = Tender::factory()->create([
        'deadline_at' => now()->subDays(1),
    ]);

    CompanyTender::factory()->create([
        'company_id' => $companyId,
        'tender_id' => $highTender->id,
        'best_score' => 90,
        'best_level' => RelevanceLevel::High,
        'first_matched_at' => now()->subDays(2),
    ]);
    CompanyTender::factory()->create([
        'company_id' => $companyId,
        'tender_id' => $mediumTender->id,
        'best_score' => 65,
        'best_level' => RelevanceLevel::Medium,
        'first_matched_at' => now()->subDays(1),
    ]);
    CompanyTender::factory()->create([
        'company_id' => $companyId,
        'tender_id' => $expiredTender->id,
        'best_score' => 50,
        'best_level' => RelevanceLevel::Weak,
        'first_matched_at' => now()->subDays(1),
    ]);

    $profile = SearchProfile::factory()->create(['company_id' => $companyId]);
    RadarRun::create([
        'company_id' => $companyId,
        'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Manual,
        'status' => RunStatus::Ok,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(9),
        'processed_count' => 100,
        'matched_count' => 3,
        'notified_count' => 2,
        'error_count' => 0,
    ]);

    $this->seed(SourceSeeder::class);
    $source = Source::where('code', 'eis')->first();
    SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => $companyId,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Error,
        'started_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(19),
        'fetched_count' => 10,
        'new_count' => 0,
        'updated_count' => 0,
        'error_count' => 4,
        'log' => 'parse error',
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('counters.new', 3)
            ->where('counters.relevant', 2) // high + medium
            ->has('highPriority', 1)
            ->where('highPriority.0.tender.title', 'Высокий приоритет')
            ->where('highPriority.0.best_score', 90)
            ->has('upcomingDeadlines', 2) // high + medium, истёкший исключён
            ->where('upcomingDeadlines.0.tender.title', 'Высокий приоритет') // ближайший дедлайн первым
            ->has('recentRuns.radar', 1)
            ->has('recentRuns.collect', 1)
            ->where('recentRuns.collect.0.error_count', 4)
            ->has('dynamics', 30) // окно заполнено нулями
        );
});

it('дашборд изолирует данные между компаниями (tenant-изоляция §13)', function () {
    $adminA = adminWithPlan();
    $adminB = adminWithPlan();

    $tenderA = Tender::factory()->create(['deadline_at' => now()->addDays(3)]);
    $tenderB = Tender::factory()->create(['deadline_at' => now()->addDays(3)]);

    CompanyTender::factory()->create([
        'company_id' => $adminA->company_id,
        'tender_id' => $tenderA->id,
        'best_score' => 95,
        'best_level' => RelevanceLevel::High,
        'first_matched_at' => now()->subDays(1),
    ]);
    CompanyTender::factory()->create([
        'company_id' => $adminB->company_id,
        'tender_id' => $tenderB->id,
        'best_score' => 95,
        'best_level' => RelevanceLevel::High,
        'first_matched_at' => now()->subDays(1),
    ]);

    // Компания A видит только своё.
    $this->actingAs($adminA)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('counters.new', 1)
            ->has('highPriority', 1)
            ->where('highPriority.0.tender.id', $tenderA->id)
            ->has('upcomingDeadlines', 1)
            ->where('upcomingDeadlines.0.tender.id', $tenderA->id)
        );
});

it('суперадмин получает пустой company-scoped дашборд', function () {
    $superadmin = User::factory()->create(['role' => UserRole::Superadmin, 'company_id' => null]);

    $this->actingAs($superadmin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('counters.new', 0)
            ->where('counters.relevant', 0)
            ->has('highPriority', 0)
            ->has('upcomingDeadlines', 0)
            ->has('recentRuns.radar', 0)
            ->has('recentRuns.collect', 0)
            ->has('dynamics', 30)
        );
});
```

> Запуск: `php artisan test tests/Feature/Dashboard/DashboardTest.php` — упадёт (контроллера/маршрута нет).

- [ ] **Implement** — `app/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\RelevanceLevel;
use App\Models\CompanyTender;
use App\Models\RadarRun;
use App\Models\SourceCollectRun;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Окно (дней) для счётчиков и графика динамики (дизайн A7 §2.8 п.1/п.5). */
    private const WINDOW_DAYS = 30;

    private const HIGH_PRIORITY_LIMIT = 10;

    private const DEADLINES_LIMIT = 10;

    private const RUNS_LIMIT = 10;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $companyId = $user?->company_id;

        // Начало окна (включительно) — N-1 дней назад, начало дня (UTC).
        $windowStart = Carbon::now()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        // Колонки tender для блоков (через relation tender — Tender глобальная, scope не нужен).
        $tenderColumns = ['id', 'title', 'region', 'price', 'deadline_at'];

        // 1. Счётчики (company_tenders авто-scoped по first_matched_at за окно).
        $windowQuery = CompanyTender::query()
            ->where('first_matched_at', '>=', $windowStart);

        $counters = [
            'new' => (clone $windowQuery)->count(),
            'relevant' => (clone $windowQuery)
                ->whereIn('best_level', [RelevanceLevel::High->value, RelevanceLevel::Medium->value])
                ->count(),
        ];

        // 2. Высокоприоритетные (best_level=high), топ по best_score.
        $highPriority = CompanyTender::query()
            ->select(['id', 'company_id', 'tender_id', 'best_score', 'best_level'])
            ->where('best_level', RelevanceLevel::High->value)
            ->with(['tender' => fn ($q) => $q->select($tenderColumns)])
            ->orderByDesc('best_score')
            ->limit(self::HIGH_PRIORITY_LIMIT)
            ->get();

        // 3. Ближайшие дедлайны: tender.deadline_at в будущем, ASC. whereHas по relation tender.
        $now = Carbon::now();
        $upcomingDeadlines = CompanyTender::query()
            ->select(['id', 'company_id', 'tender_id'])
            ->whereHas('tender', fn ($q) => $q->where('deadline_at', '>=', $now))
            ->with(['tender' => fn ($q) => $q->select($tenderColumns)])
            ->orderBy(
                CompanyTender::query()
                    ->getModel()
                    ->tender()
                    ->getRelated()
                    ->newQuery()
                    ->select('deadline_at')
                    ->whereColumn('tenders.id', 'company_tenders.tender_id')
            )
            ->limit(self::DEADLINES_LIMIT)
            ->get();

        // 4. Последние запуски: radar (авто-scoped) + collect (явный company_id).
        $recentRuns = [
            'radar' => RadarRun::query()
                ->select(['id', 'company_id', 'search_profile_id', 'trigger', 'status', 'started_at', 'finished_at', 'processed_count', 'matched_count', 'notified_count', 'error_count'])
                ->with('searchProfile:id,name')
                ->latest('started_at')
                ->limit(self::RUNS_LIMIT)
                ->get(),
            'collect' => SourceCollectRun::query()
                ->select(['id', 'source_id', 'company_id', 'trigger', 'status', 'started_at', 'finished_at', 'fetched_count', 'new_count', 'error_count'])
                ->where('company_id', $companyId)
                ->with('source:id,name')
                ->latest('started_at')
                ->limit(self::RUNS_LIMIT)
                ->get(),
        ];

        // 5. Динамика: агрегат по date(first_matched_at) за окно, дозаполнен нулями.
        $dynamics = $this->dynamics($windowStart);

        return Inertia::render('Dashboard', [
            'counters' => $counters,
            'highPriority' => $highPriority,
            'upcomingDeadlines' => $upcomingDeadlines,
            'recentRuns' => $recentRuns,
            'dynamics' => $dynamics,
        ]);
    }

    /**
     * Агрегат company_tenders по дню first_matched_at за окно, с нулевыми днями.
     *
     * @return list<array{date: string, count: int}>
     */
    private function dynamics(Carbon $windowStart): array
    {
        // PostgreSQL: группировка по дате с приведением к ::date.
        $rows = CompanyTender::query()
            ->where('first_matched_at', '>=', $windowStart)
            ->selectRaw('first_matched_at::date as d, count(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd'); // ['2026-06-10' => 3, ...]

        $series = [];
        $cursor = $windowStart->copy();
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $key = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'count' => (int) ($rows[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }
}
```

> **Заметки по реализации:**
> - `whereIn('best_level', [...])` сравнивает по строковым значениям enum (`->value`) — колонка `best_level` хранит строку, cast — на чтение модели.
> - Динамика: `selectRaw('first_matched_at::date as d, count(*) as c')` — PG-синтаксис `::date`; ключ `pluck` — строка `Y-m-d` (PG возвращает `date` как `'2026-06-10'`). Если драйвер вернёт ключ с иным форматом — нормализовать через `Carbon::parse`, но для PG16 формат стабилен.
> - `orderBy(subquery)` сортирует company_tenders по `tenders.deadline_at` без join, не нарушая scope. Альтернатива (если phpstan на subquery-объекте ругается): `->join('tenders', 'tenders.id', '=', 'company_tenders.tender_id')->orderBy('tenders.deadline_at')->select('company_tenders.*')` — но join ломает `->with('tender')` select-проекцию, поэтому предпочтителен orderBy-subquery. На L6 subquery-builder типизируется как `Builder` — допустимо.
> - Суперадмин (`company_id === null`): scope `company` неактивен, НО `where('company_id', null)` в collect даёт пусто; для company_tenders/radar_runs без company_id scope вернёт всё — поэтому **для суперадмина дашборд не предназначен**. Чтобы не «протекли» все компании, добавить ранний guard.

- [ ] **Implement (guard суперадмина)** — вставить в начало `index()` после получения `$companyId`, ДО запросов. Суперадмин (`company_id === null`) отключил бы tenant-scope и показал бы данные ВСЕХ компаний, поэтому возвращаем пустой дашборд напрямую (без обращения к БД для динамики — `emptyDynamics()`):

```php
        // Суперадмин не привязан к компании: company-scoped дашборд пуст
        // (его данные — на /runs eisRuns). Без guard'а company_id=null отключил бы
        // tenant-scope и показал бы данные ВСЕХ компаний. Динамику строим нулевым
        // рядом (emptyDynamics), а не запросом — иначе агрегат без scope посчитал бы все компании.
        if ($companyId === null) {
            return Inertia::render('Dashboard', [
                'counters' => ['new' => 0, 'relevant' => 0],
                'highPriority' => [],
                'upcomingDeadlines' => [],
                'recentRuns' => ['radar' => [], 'collect' => []],
                'dynamics' => $this->emptyDynamics(),
            ]);
        }
```

> Также добавить приватный метод `emptyDynamics()` (рядом с `dynamics()`):

```php
    /**
     * Нулевой ряд динамики на окно (для суперадмина — без обращения к БД).
     *
     * @return list<array{date: string, count: int}>
     */
    private function emptyDynamics(): array
    {
        $series = [];
        $cursor = Carbon::now()->subDays(self::WINDOW_DAYS - 1)->startOfDay();
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $series[] = ['date' => $cursor->format('Y-m-d'), 'count' => 0];
            $cursor->addDay();
        }

        return $series;
    }
```

> Итог: основной путь `index()` использует `dynamics()` (scoped, обращается к БД), guard суперадмина — `emptyDynamics()` (30 нулей, без БД).

- [ ] **Implement (routes — SURGICAL, общий с C3)** — в `routes/web.php`:

1. Добавить импорт контроллера (рядом с прочими `use App\Http\Controllers\...`):

```php
use App\Http\Controllers\DashboardController;
```

2. Заменить closure-маршрут `/dashboard` (якорь — строки 19–21):

```php
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');
```

на:

```php
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');
```

> `name('dashboard')` и `middleware('auth')` сохранены — `Route::get('/')` редирект и тесты на `route('dashboard')` не ломаются. Импорт `use Inertia\Inertia;` НЕ удалять (используется другими маршрутами/может использоваться C3). **Не воспроизводить файл целиком — только эти две правки.**

- [ ] **Verify:** `php artisan test tests/Feature/Dashboard/DashboardTest.php` зелёный; `./vendor/bin/pint --test`; `./vendor/bin/phpstan analyse` (L6=0).

- [ ] **Commit:** `git add app/Http/Controllers/DashboardController.php routes/web.php tests/Feature/Dashboard/DashboardTest.php && git commit` — message:
  `A7 C4 Task 8: DashboardController — company-scoped props (счётчики/приоритет/дедлайны/запуски/динамика) + tenant-изоляция`
  trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

### Task 9: `resources/js/Pages/Dashboard.vue` — замена заглушки

**Files:**
- Modify (полная замена) `resources/js/Pages/Dashboard.vue`

**Interfaces:**
- Consumes (props из Task 8): `counters`, `highPriority`, `upcomingDeadlines`, `recentRuns`, `dynamics`. `defineProps` ДОЛЖЕН точно совпадать с контрактом Task 8.
- Uses: `AuthenticatedLayout`, `Head`, `Link` (`@inertiajs/vue3`), глобальный `route()` (Ziggy).

**Решение по графику (обосновано):** inline-SVG bar-chart БЕЗ новой npm-зависимости. Причины: (1) дизайн A7 §2.8 п.5 явно предпочитает «без тяжёлой зависимости»; (2) данные — простой ряд `{date, count}` фиксированной длины (30), bar-chart рисуется десятком строк SVG; (3) Chart.js/ApexCharts добавили бы вес бандла, SSR-нюансы и зависимость ради одного графика. Реализуем как самодостаточный `<svg>` с `<rect>` на каждый день, высота = `count / max * H`.

#### Шаги

- [ ] **Implement** — заменить весь `resources/js/Pages/Dashboard.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    counters: { type: Object, required: true }, // { new, relevant }
    highPriority: { type: Array, required: true }, // [{ id, best_score, best_level, tender: {...} }]
    upcomingDeadlines: { type: Array, required: true }, // [{ id, tender: {...} }]
    recentRuns: { type: Object, required: true }, // { radar: [...], collect: [...] }
    dynamics: { type: Array, required: true }, // [{ date, count }]
});

const money = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽');
const date = (v) => (v ? new Date(v).toLocaleDateString('ru-RU') : '—');
const dateTime = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');

const levelClass = (level) => ({
    high: 'bg-green-100 text-green-800',
    medium: 'bg-blue-100 text-blue-800',
    weak: 'bg-yellow-100 text-yellow-800',
    low: 'bg-gray-100 text-gray-600',
}[level] ?? 'bg-gray-100 text-gray-600');

const statusClass = (s) => ({ ok: 'text-green-700', error: 'text-red-700', running: 'text-gray-500' }[s] ?? 'text-gray-700');
const statusLabel = (v) => ({ running: 'Запущен', ok: 'Успешно', error: 'Ошибка' }[v] ?? v);
const triggerLabel = (v) => ({ import: 'Импорт', schedule: 'Расписание', manual: 'Вручную' }[v] ?? v);

// --- inline-SVG bar-chart по dynamics ---
const chartW = 720;
const chartH = 120;
const barGap = 2;
const maxCount = computed(() => Math.max(1, ...props.dynamics.map((d) => d.count)));
const barWidth = computed(() => props.dynamics.length ? (chartW - barGap * (props.dynamics.length - 1)) / props.dynamics.length : 0);
const bars = computed(() =>
    props.dynamics.map((d, i) => {
        const h = Math.round((d.count / maxCount.value) * (chartH - 16));
        return {
            x: i * (barWidth.value + barGap),
            y: chartH - h,
            w: barWidth.value,
            h,
            date: d.date,
            count: d.count,
        };
    }),
);
const totalFound = computed(() => props.dynamics.reduce((acc, d) => acc + d.count, 0));
</script>

<template>
    <Head title="Дашборд" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Дашборд</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">

                <!-- 1. Счётчики -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <div class="text-sm text-gray-500">Новых закупок (30 дней)</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">{{ counters.new }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <div class="text-sm text-gray-500">Релевантных (30 дней)</div>
                        <div class="mt-1 text-3xl font-semibold text-indigo-600">{{ counters.relevant }}</div>
                    </div>
                </div>

                <!-- 5. График динамики (inline-SVG) -->
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h3 class="font-semibold text-gray-800">Динамика найденных (30 дней)</h3>
                        <span class="text-sm text-gray-500">всего: {{ totalFound }}</span>
                    </div>
                    <svg
                        :viewBox="`0 0 ${chartW} ${chartH}`"
                        class="h-32 w-full"
                        preserveAspectRatio="none"
                        role="img"
                        aria-label="График динамики найденных закупок за 30 дней"
                    >
                        <rect
                            v-for="b in bars"
                            :key="b.date"
                            :x="b.x"
                            :y="b.y"
                            :width="b.w"
                            :height="b.h"
                            rx="1"
                            class="fill-indigo-400"
                        >
                            <title>{{ b.date }}: {{ b.count }}</title>
                        </rect>
                    </svg>
                </div>

                <!-- 2. Высокоприоритетные закупки -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Высокий приоритет</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Название</th>
                                <th class="px-3 py-2">Регион</th>
                                <th class="px-3 py-2">Сумма</th>
                                <th class="px-3 py-2">Дедлайн</th>
                                <th class="px-3 py-2">Балл</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="ct in highPriority" :key="'h' + ct.id" class="hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    <Link :href="route('tenders.show', ct.id)" class="font-medium text-indigo-600 hover:underline">
                                        {{ ct.tender.title }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ ct.tender.region ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ money(ct.tender.price) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ date(ct.tender.deadline_at) }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-2 py-1 text-xs font-medium" :class="levelClass(ct.best_level)">{{ ct.best_score }}</span>
                                </td>
                            </tr>
                            <tr v-if="!highPriority.length"><td colspan="5" class="px-3 py-4 text-gray-400">Высокоприоритетных закупок нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- 3. Ближайшие дедлайны -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Ближайшие дедлайны</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Название</th>
                                <th class="px-3 py-2">Регион</th>
                                <th class="px-3 py-2">Сумма</th>
                                <th class="px-3 py-2">Дедлайн</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="ct in upcomingDeadlines" :key="'d' + ct.id" class="hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    <Link :href="route('tenders.show', ct.id)" class="font-medium text-indigo-600 hover:underline">
                                        {{ ct.tender.title }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ ct.tender.region ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ money(ct.tender.price) }}</td>
                                <td class="px-3 py-2 font-medium text-gray-800">{{ date(ct.tender.deadline_at) }}</td>
                            </tr>
                            <tr v-if="!upcomingDeadlines.length"><td colspan="4" class="px-3 py-4 text-gray-400">Предстоящих дедлайнов нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Последние запуски + ошибки источников -->
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-3 font-semibold text-gray-800">Последние запуски радара</h3>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Направление</th>
                                    <th class="px-3 py-2">Статус</th>
                                    <th class="px-3 py-2">Обраб./Матчей</th>
                                    <th class="px-3 py-2">Начало</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y bg-white">
                                <tr v-for="r in recentRuns.radar" :key="'rr' + r.id">
                                    <td class="px-3 py-2">{{ r.searchProfile?.name ?? '—' }}</td>
                                    <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                    <td class="px-3 py-2">{{ r.processed_count }} / {{ r.matched_count }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ dateTime(r.started_at) }}</td>
                                </tr>
                                <tr v-if="!recentRuns.radar.length"><td colspan="4" class="px-3 py-4 text-gray-400">Запусков радара нет.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-3 font-semibold text-gray-800">Последний сбор источников</h3>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Источник</th>
                                    <th class="px-3 py-2">Статус</th>
                                    <th class="px-3 py-2">Найдено/Новых</th>
                                    <th class="px-3 py-2">Ошибок</th>
                                    <th class="px-3 py-2">Начало</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y bg-white">
                                <tr v-for="r in recentRuns.collect" :key="'cr' + r.id">
                                    <td class="px-3 py-2">{{ r.source?.name ?? '—' }}</td>
                                    <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                    <td class="px-3 py-2">{{ r.fetched_count }} / {{ r.new_count }}</td>
                                    <td class="px-3 py-2" :class="r.error_count > 0 ? 'font-semibold text-red-700' : 'text-gray-500'">{{ r.error_count }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ dateTime(r.started_at) }}</td>
                                </tr>
                                <tr v-if="!recentRuns.collect.length"><td colspan="5" class="px-3 py-4 text-gray-400">Сборов нет.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

> **Заметки:**
> - `defineProps` точно повторяет контракт Task 8 (`counters`/`highPriority`/`upcomingDeadlines`/`recentRuns`/`dynamics`). Vue-тест не требуется — Feature-тест Task 8 валидирует props серверно.
> - `Link :href="route('tenders.show', ct.id)"` ведёт на `company_tenders.id` (как в `Tenders/Index.vue`) — маршрут `tenders.show` принимает `{companyTender}`.
> - `recentRuns.collect.0.error_count > 0` подсвечивается красным — это «ошибки источников» из §13.1 п.4.
> - Форматирование (`money`/`date`/`dateTime`/`levelClass`/`statusLabel`/`triggerLabel`) повторяет хелперы `Tenders/Index.vue` и `Runs/Index.vue` для визуальной консистентности.

- [ ] **Verify:** `npm run build` (или `npm run lint`, если настроен) — сборка без ошибок; повторно `php artisan test tests/Feature/Dashboard/DashboardTest.php` (props не менялись — должен остаться зелёным).

- [ ] **Commit:** `git add resources/js/Pages/Dashboard.vue && git commit` — message:
  `A7 C4 Task 9: Dashboard.vue — секции дашборда + inline-SVG график динамики`
  trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

## Риски и mitigations C4

- **`routes/web.php` общий с C3.** Кластер C3 (ручной запуск радара / тестовое сообщение MAX) тоже правит этот файл. Митигация: C4 трогает ТОЛЬКО блок `/dashboard` (закрытая closure → контроллер) + один `use`-импорт. При merge-конфликте — конфликт изолирован в строках 19–21 и в зоне импортов; разрешается тривиально (обе правки additive). Не воспроизводить файл целиком.
- **Реальные имена полей.** Сверены по миграциям: `company_tenders.first_matched_at`/`best_level`/`best_score`, `tenders.deadline_at`/`price`, `radar_runs.processed_count`/`matched_count`/`notified_count`, `source_collect_runs.error_count`/`company_id` (nullable). Любое расхождение в props сломает Feature-тест Task 8 — это и есть страховка.
- **Tenant-scope vs суперадмин.** `BelongsToCompany` отключает scope при `company_id === null`. Без guard'а суперадмин увидел бы данные ВСЕХ компаний (утечка). Митигация: ранний guard в `index()` + отдельный тест «суперадмин → пустой дашборд». `SourceCollectRun` без scope — всегда явный `where('company_id', $companyId)`.
- **PG-специфика динамики.** `first_matched_at::date` и группировка — синтаксис PostgreSQL (проект на PG16, не abstractable). Зафиксировано в заметке; ключи `pluck` — строки `Y-m-d`.
- **`orderBy(subquery)` для дедлайнов.** Сортировка по `tenders.deadline_at` без join, чтобы не ломать `with('tender')`-проекцию. Если larastan L6 ругнётся на тип subquery-builder — fallback на join+select описан в заметке Task 8.


---


