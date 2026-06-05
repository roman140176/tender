# A1: Каркас и модель данных — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Рабочий каркас Laravel 13 (Inertia + Vue 3) с полной финальной схемой БД из спеки §5, tenant-изоляцией, auth (login-only), сидерами и CI.

> **Факт исполнения (T1):** установлен Laravel 13.14 + Breeze 2.4.2 (актуальный мажор; план писался под «12»). Redis-клиент — predis (ext-redis на хосте нет). Все команды PHP — строго через бинарь `php8.3` (дефолтный `php` на хосте — 7.3!).

**Architecture:** Монолит Laravel; PostgreSQL 16 + Redis в docker для dev; вся схема БД накатывается сразу (решение спеки «миграции — финальная схема, без партиций»); tenant-изоляция через `TenantContext`-синглтон + global scope, работающая и в HTTP, и в джобах.

**Tech Stack:** PHP 8.3, Laravel 13, Inertia.js + Vue 3 + Tailwind, PostgreSQL 16 (jsonb, tsvector, pg_trgm), Redis 7 (predis), Pest, Pint, Larastan, GitHub Actions.

**Спека:** `docs/superpowers/specs/2026-06-05-tender-radar-design.md` (раздел 5 — схема, раздел 7.3 — scoring_weights, раздел 11 — изоляция).

---

### Task 1: Скелет Laravel + Vue starter kit

**Files:**
- Create: весь скелет Laravel в корне репо (`app/`, `config/`, `routes/`, `resources/`, …)

- [ ] **Step 1: Проверить окружение**

Run: `php -v && composer -V && node -v && docker compose version`
Expected: PHP ≥ 8.3, Composer 2.x, Node ≥ 20, docker compose v2. Если PHP < 8.3 — остановиться и сообщить (вариант: Laravel Sail, согласовать отдельно).

- [ ] **Step 2: Создать проект во временной папке (репо не пустое — там ТЗ и docs)**

```bash
composer global require laravel/installer
~/.config/composer/vendor/bin/laravel new tender-app --vue --pest --database=pgsql --no-interaction
# (если laravel-бинарь в другом месте: composer global config bin-dir --absolute)
```

Рабочая директория: `/tmp` (или `$HOME/tmp`). Если установщик недоступен — фолбэк:
`composer create-project laravel/laravel tender-app && cd tender-app && composer require laravel/breeze --dev && php artisan breeze:install vue --pest`

- [ ] **Step 3: Перенести скелет в репозиторий, не задев .git, ТЗ и docs**

```bash
rm -rf /tmp/tender-app/.git
cp -an /tmp/tender-app/. /home/roman/sites/www/tender/
rm -rf /tmp/tender-app
cd /home/roman/sites/www/tender && git status
```

Expected: новые файлы скелета, существующие `TЗ`, `docs/`, `.git` нетронуты. Проверить, что `.gitignore` скелета попал в корень (содержит `/vendor`, `/node_modules`, `.env`).

- [ ] **Step 4: Собрать фронт и прогнать стартовые тесты (sqlite по умолчанию в phpunit.xml)**

```bash
npm install && npm run build
php artisan key:generate --ansi
php artisan test
```

Expected: build OK, стартовые auth-тесты PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "A1: скелет Laravel 12 + Vue starter kit"
```

---

### Task 2: Dev-инфраструктура: PostgreSQL 16 + Redis, тестовая БД

**Files:**
- Create: `docker-compose.dev.yml`, `docker/postgres/init/01-test-db.sql`
- Modify: `.env`, `.env.example`, `phpunit.xml`

- [ ] **Step 1: Написать docker-compose.dev.yml**

```yaml
services:
  postgres:
    image: postgres:16
    environment:
      POSTGRES_USER: tender
      POSTGRES_PASSWORD: secret
      POSTGRES_DB: tender
    ports:
      - "54320:5432"
    volumes:
      - pg_data:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d
  redis:
    image: redis:7-alpine
    ports:
      - "63790:6379"
volumes:
  pg_data: {}
```

- [ ] **Step 2: Скрипт создания тестовой БД** — `docker/postgres/init/01-test-db.sql`

```sql
CREATE DATABASE tender_test OWNER tender;
```

- [ ] **Step 3: Поднять и проверить**

Run: `docker compose -f docker-compose.dev.yml up -d && sleep 5 && docker compose -f docker-compose.dev.yml exec postgres psql -U tender -l`
Expected: базы `tender` и `tender_test` в списке.

- [ ] **Step 4: Настроить .env и .env.example (одинаковые DB-блоки)**

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=54320
DB_DATABASE=tender
DB_USERNAME=tender
DB_PASSWORD=secret

REDIS_HOST=127.0.0.1
REDIS_PORT=63790
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

- [ ] **Step 5: Переключить тесты на pgsql** — в `phpunit.xml` заменить sqlite-блок на:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="54320"/>
<env name="DB_DATABASE" value="tender_test"/>
<env name="DB_USERNAME" value="tender"/>
<env name="DB_PASSWORD" value="secret"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="CACHE_STORE" value="array"/>
```

(строки `<env name="DB_CONNECTION" value="sqlite"/>` и `<env name="DB_DATABASE" value=":memory:"/>` удалить.)

- [ ] **Step 6: Миграция и тесты на PostgreSQL**

Run: `php artisan migrate:fresh && php artisan test`
Expected: миграции скелета накатаны, тесты PASS (теперь на pgsql).

- [ ] **Step 7: Commit**

```bash
git add docker-compose.dev.yml docker/ .env.example phpunit.xml
git commit -m "A1: docker-compose dev (pg16+redis), тесты на pgsql"
```

---

### Task 3: Качество кода: Pint, Larastan, CI

**Files:**
- Create: `phpstan.neon`, `.github/workflows/ci.yml`

- [ ] **Step 1: Установить larastan**

Run: `composer require --dev "larastan/larastan:^3.0"`

- [ ] **Step 2: phpstan.neon**

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    paths:
        - app
    level: 6
```

- [ ] **Step 3: Прогнать локально**

Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: оба PASS (на чистом скелете).

- [ ] **Step 4: CI workflow** — `.github/workflows/ci.yml`

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env: { POSTGRES_USER: tender, POSTGRES_PASSWORD: secret, POSTGRES_DB: tender_test }
        ports: ["54320:5432"]
        options: >-
          --health-cmd "pg_isready -U tender" --health-interval 5s --health-timeout 5s --health-retries 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3", extensions: "pgsql, redis, zip, soap" }
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --no-progress
      - run: php artisan test
```

- [ ] **Step 5: Commit**

```bash
git add phpstan.neon .github composer.json composer.lock
git commit -m "A1: pint + larastan(level 6) + CI"
```

---

### Task 4: Доменные enum'ы

**Files:**
- Create: `app/Enums/UserRole.php`, `KeywordType.php`, `KeywordOrigin.php`, `LawType.php`, `TenderSourceStatus.php`, `WorkflowStatus.php`, `RelevanceLevel.php`, `ActionRecommendation.php`, `SourceConnectionType.php`, `RunStatus.php`, `RunTrigger.php`, `NotificationChannel.php`, `NotificationStatus.php`, `ScheduleType.php`, `SubscriptionStatus.php`
- Test: `tests/Unit/Enums/RelevanceLevelTest.php`

- [ ] **Step 1: Failing test на ключевую логику уровня (полуинтервалы «≥» — спека §7.4)**

`tests/Unit/Enums/RelevanceLevelTest.php`:

```php
<?php

use App\Enums\RelevanceLevel;

it('маппит балл в уровень по полуинтервалам', function (int $score, RelevanceLevel $level) {
    expect(RelevanceLevel::fromScore($score))->toBe($level);
})->with([
    [100, RelevanceLevel::High], [80, RelevanceLevel::High],
    [79, RelevanceLevel::Medium], [60, RelevanceLevel::Medium],
    [59, RelevanceLevel::Weak], [40, RelevanceLevel::Weak],
    [39, RelevanceLevel::Low], [0, RelevanceLevel::Low],
]);
```

- [ ] **Step 2: Run test → FAIL** (`php artisan test --filter=RelevanceLevel`, "Class RelevanceLevel not found")

- [ ] **Step 3: Реализовать все enum'ы** (значения — string, в БД хранятся строками)

`app/Enums/RelevanceLevel.php`:

```php
<?php

namespace App\Enums;

enum RelevanceLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Weak = 'weak';
    case Low = 'low';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::High,
            $score >= 60 => self::Medium,
            $score >= 40 => self::Weak,
            default => self::Low,
        };
    }
}
```

Остальные — простые backed-enum'ы (по одному файлу, namespace `App\Enums`):

```php
enum UserRole: string { case Superadmin = 'superadmin'; case Admin = 'admin'; case Manager = 'manager'; case Viewer = 'viewer'; }
enum KeywordType: string { case Main = 'main'; case Exact = 'exact'; case Broad = 'broad'; case Negative = 'negative'; case HighPriority = 'high_priority'; case LowPriority = 'low_priority';
    public function isPositive(): bool { return in_array($this, [self::Main, self::Exact, self::Broad], true); } }
enum KeywordOrigin: string { case Manual = 'manual'; case Ai = 'ai'; case Dictionary = 'dictionary'; }
enum LawType: string { case Fz44 = 'fz44'; case Fz223 = 'fz223'; case Commercial = 'commercial'; case Other = 'other'; }
enum TenderSourceStatus: string { case Active = 'active'; case Completed = 'completed'; case Cancelled = 'cancelled'; case Unknown = 'unknown'; }
enum WorkflowStatus: string { case New = 'new'; case Interesting = 'interesting'; case ReviewDocs = 'review_docs'; case HandedOver = 'handed_over'; case InProgress = 'in_progress'; case Declined = 'declined'; case Irrelevant = 'irrelevant'; case Archived = 'archived'; case Participating = 'participating'; case NotParticipating = 'not_participating'; case Won = 'won'; case Lost = 'lost'; }
enum ActionRecommendation: string { case HandOver = 'hand_over'; case ReviewDocs = 'review_docs'; case Monitor = 'monitor'; case Decline = 'decline'; }
enum SourceConnectionType: string { case Api = 'api'; case OpenData = 'open_data'; case Manual = 'manual'; case Parsing = 'parsing'; }
enum RunStatus: string { case Running = 'running'; case Ok = 'ok'; case Error = 'error'; }
enum RunTrigger: string { case Manual = 'manual'; case Schedule = 'schedule'; case Import = 'import'; }
enum NotificationChannel: string { case Telegram = 'telegram'; case Email = 'email'; case Web = 'web'; case Webhook = 'webhook'; }
enum NotificationStatus: string { case Queued = 'queued'; case Sent = 'sent'; case Failed = 'failed'; }
enum ScheduleType: string { case Manual = 'manual'; case Daily = 'daily'; case Weekly = 'weekly'; case Cron = 'cron'; }
enum SubscriptionStatus: string { case Trial = 'trial'; case Active = 'active'; case Expired = 'expired'; }
```

- [ ] **Step 4: Run test → PASS** (`php artisan test --filter=RelevanceLevel`)

- [ ] **Step 5: Commit** — `git add app/Enums tests/Unit/Enums && git commit -m "A1: доменные enum'ы"`

---

### Task 5: Tenant-изоляция: TenantContext + BelongsToCompany + middleware

**Files:**
- Create: `app/Tenancy/TenantContext.php`, `app/Tenancy/BelongsToCompany.php`, `app/Http/Middleware/SetTenantContext.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Tenancy/BelongsToCompanyTest.php` (тест пишется здесь, прогоняется в Task 6 — нужна модель SearchProfile; см. Step 4)

- [ ] **Step 1: TenantContext (scoped-синглтон)**

`app/Tenancy/TenantContext.php`:

```php
<?php

namespace App\Tenancy;

final class TenantContext
{
    private ?int $companyId = null;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }
}
```

Регистрация в `app/Providers/AppServiceProvider::register()`:

```php
$this->app->scoped(\App\Tenancy\TenantContext::class);
```

- [ ] **Step 2: Трейт глобального scope + автозаполнение company_id**

`app/Tenancy/BelongsToCompany.php`:

```php
<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyId = app(TenantContext::class)->id();
            if ($companyId !== null) {
                $builder->where($builder->qualifyColumn('company_id'), $companyId);
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', app(TenantContext::class)->id());
            }
        });
    }

    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope('company');
    }
}
```

- [ ] **Step 3: Middleware + регистрация**

`app/Http/Middleware/SetTenantContext.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class SetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        app(TenantContext::class)->set($request->user()?->company_id);

        return $next($request);
    }
}
```

В `bootstrap/app.php` внутри `->withMiddleware(...)`:

```php
$middleware->web(append: [\App\Http\Middleware\SetTenantContext::class]);
```

- [ ] **Step 4: Failing test изоляции** — `tests/Feature/Tenancy/BelongsToCompanyTest.php`:

```php
<?php

use App\Models\Company;
use App\Models\SearchProfile;
use App\Tenancy\TenantContext;

it('скрывает чужие записи при установленном тенанте и отдаёт все без него', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    SearchProfile::factory()->for($a)->create(['name' => 'A-профиль']);
    SearchProfile::factory()->for($b)->create(['name' => 'B-профиль']);

    app(TenantContext::class)->set($a->id);
    expect(SearchProfile::query()->pluck('name')->all())->toBe(['A-профиль']);

    app(TenantContext::class)->set(null);
    expect(SearchProfile::query()->count())->toBe(2);
});

it('автозаполняет company_id из контекста при создании', function () {
    $a = Company::factory()->create();
    app(TenantContext::class)->set($a->id);

    $profile = SearchProfile::factory()->create(['company_id' => null]);
    expect($profile->company_id)->toBe($a->id);
});
```

Run: `php artisan test --filter=BelongsToCompany`
Expected: FAIL — "Class App\Models\SearchProfile not found" (модель появится в Task 7; это нормально — Task 5 коммитится с падающим тестом, помеченным `->todo()`? Нет: пометить оба теста `->skip('ждёт Task 7')` и снять skip в Task 7, Step 6).

- [ ] **Step 5: Commit**

```bash
git add app/Tenancy app/Http/Middleware/SetTenantContext.php bootstrap/app.php app/Providers/AppServiceProvider.php tests/Feature/Tenancy
git commit -m "A1: tenant-изоляция (TenantContext, BelongsToCompany, middleware)"
```

---

### Task 6: Companies, Users, Plans, Subscriptions

**Files:**
- Create: миграции `*_create_companies_table.php`, `*_add_company_fields_to_users_table.php`, `*_create_plans_and_subscriptions_tables.php`; модели `app/Models/Company.php`, `Plan.php`, `Subscription.php`; `database/factories/CompanyFactory.php`; `database/seeders/PlanSeeder.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Models/CompanyUserPlanTest.php`

- [ ] **Step 1: Failing test**

`tests/Feature/Models/CompanyUserPlanTest.php`:

```php
<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;

it('связывает компанию, пользователей и подписку', function () {
    $company = Company::factory()->create(['industries' => ['металлообработка']]);
    $admin = User::factory()->for($company)->create(['role' => UserRole::Admin]);

    $this->seed(\Database\Seeders\PlanSeeder::class);
    $plan = Plan::where('code', 'basic')->firstOrFail();
    $company->subscriptions()->create([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'started_at' => now(),
    ]);

    expect($company->users->first()->is($admin))->toBeTrue()
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($company->industries)->toBe(['металлообработка'])
        ->and($company->activeSubscription()?->plan->code)->toBe('basic')
        ->and(Plan::count())->toBe(3);
});

it('суперадмин не принадлежит компании', function () {
    $root = User::factory()->superadmin()->create();
    expect($root->company_id)->toBeNull()->and($root->role)->toBe(UserRole::Superadmin);
});
```

- [ ] **Step 2: Run → FAIL** (`php artisan test --filter=CompanyUserPlan`, нет таблиц/моделей)

- [ ] **Step 3: Миграции**

`*_create_companies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('inn', 12)->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('industries')->default('[]');
            $table->jsonb('products')->default('[]');
            $table->jsonb('services')->default('[]');
            $table->jsonb('contacts')->default('[]');
            $table->string('notification_email')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->string('crm_webhook_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
```

`*_add_company_fields_to_users_table.php`:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        $table->string('role')->default('manager')->after('email');
        $table->boolean('is_active')->default(true)->after('role');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropConstrainedForeignId('company_id');
        $table->dropColumn(['role', 'is_active']);
    });
}
```

`*_create_plans_and_subscriptions_tables.php`:

```php
public function up(): void
{
    Schema::create('plans', function (Blueprint $table) {
        $table->id();
        $table->string('code')->unique();
        $table->string('name');
        $table->unsignedSmallInteger('max_search_profiles');
        $table->unsignedSmallInteger('max_sources');
        $table->unsignedSmallInteger('max_users');
        $table->unsignedInteger('max_ai_calls_per_month')->nullable();
        $table->boolean('allow_schedules')->default(false);
        $table->boolean('allow_ai')->default(false);
        $table->boolean('allow_api')->default(false);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('subscriptions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('plan_id')->constrained();
        $table->string('status')->default('trial');
        $table->timestamp('started_at');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('subscriptions');
    Schema::dropIfExists('plans');
}
```

- [ ] **Step 4: Модели и фабрики**

`app/Models/Company.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'industries' => 'array',
            'products' => 'array',
            'services' => 'array',
            'contacts' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', ['trial', 'active'])
            ->latest('started_at')
            ->first();
    }
}
```

`app/Models/User.php` — добавить в существующую модель:

```php
use App\Enums\UserRole;

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
        'is_active' => 'boolean',
    ];
}

public function company(): BelongsTo
{
    return $this->belongsTo(Company::class);
}
```

(в `$fillable` добавить `company_id`, `role`, `is_active`.)

`app/Models/Plan.php` и `app/Models/Subscription.php`:

```php
class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allow_schedules' => 'boolean', 'allow_ai' => 'boolean', 'allow_api' => 'boolean', 'is_active' => 'boolean'];
    }
}

class Subscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => \App\Enums\SubscriptionStatus::class, 'started_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```

`database/factories/CompanyFactory.php`:

```php
public function definition(): array
{
    return [
        'name' => 'ООО «'.fake()->company().'»',
        'inn' => (string) fake()->numerify('##########'),
        'is_active' => true,
    ];
}
```

`database/factories/UserFactory.php` — в `definition()` добавить `'company_id' => Company::factory()`, `'role' => UserRole::Manager`, `'is_active' => true`; добавить state:

```php
public function superadmin(): static
{
    return $this->state(fn () => ['company_id' => null, 'role' => UserRole::Superadmin]);
}
```

- [ ] **Step 5: PlanSeeder (тарифы из ТЗ §25)**

`database/seeders/PlanSeeder.php`:

```php
public function run(): void
{
    foreach ([
        ['code' => 'basic', 'name' => 'Базовый', 'max_search_profiles' => 3, 'max_sources' => 5, 'max_users' => 3,
         'allow_schedules' => false, 'allow_ai' => false, 'allow_api' => false],
        ['code' => 'standard', 'name' => 'Стандартный', 'max_search_profiles' => 10, 'max_sources' => 10, 'max_users' => 10,
         'allow_schedules' => true, 'allow_ai' => true, 'allow_api' => false, 'max_ai_calls_per_month' => 500],
        ['code' => 'professional', 'name' => 'Профессиональный', 'max_search_profiles' => 50, 'max_sources' => 100, 'max_users' => 50,
         'allow_schedules' => true, 'allow_ai' => true, 'allow_api' => true, 'max_ai_calls_per_month' => 5000],
    ] as $plan) {
        Plan::updateOrCreate(['code' => $plan['code']], $plan);
    }
}
```

- [ ] **Step 6: Run → PASS** (`php artisan migrate && php artisan test --filter=CompanyUserPlan`)

- [ ] **Step 7: Commit** — `git add -A && git commit -m "A1: companies, users(role), plans, subscriptions"`

---

### Task 7: SearchProfiles, Keywords, Synonyms

> **Заметки с ревью Task 5:** (1) снять `ignoreErrors: trait.unused` из phpstan.neon — трейт станет используемым; (2) добавить докблок на `BelongsToCompany::withoutTenancy()`: «отключает только read-scope; creating-автозаполнение продолжает работать»; (3) при снятии skip убедиться, что тест автозаполнения проверяет именно контекст (явный `company_id => null` перекрывает фабричный дефолт — ок).

**Files:**
- Create: миграция `*_create_search_tables.php`; модели `SearchProfile.php`, `Keyword.php`, `Synonym.php`; фабрики `SearchProfileFactory.php`, `KeywordFactory.php`
- Modify: `tests/Feature/Tenancy/BelongsToCompanyTest.php` (снять skip)
- Test: `tests/Feature/Models/SearchProfileTest.php`

- [ ] **Step 1: Failing test**

`tests/Feature/Models/SearchProfileTest.php`:

```php
<?php

use App\Enums\KeywordType;
use App\Models\SearchProfile;

it('нельзя активировать профиль без позитивного ключа', function () {
    $profile = SearchProfile::factory()->create();
    $profile->keywords()->create(['value' => 'аренда', 'type' => KeywordType::Negative]);
    expect($profile->canActivate())->toBeFalse();

    $profile->keywords()->create(['value' => 'металлоконструкции', 'type' => KeywordType::Main]);
    expect($profile->refresh()->canActivate())->toBeTrue();
});

it('хранит jsonb-настройки и дефолты', function () {
    $profile = SearchProfile::factory()->create([
        'law_types' => ['fz44', 'fz223'],
        'regions' => ['Москва'],
    ]);
    expect($profile->law_types)->toBe(['fz44', 'fz223'])
        ->and($profile->notification_threshold)->toBe(60)
        ->and($profile->deadline_min_days)->toBe(0)
        ->and($profile->exclude_on_negative_in_title)->toBeFalse();
});
```

- [ ] **Step 2: Run → FAIL** (нет таблиц/моделей)

- [ ] **Step 3: Миграция** `*_create_search_tables.php`:

```php
public function up(): void
{
    Schema::create('search_profiles', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->boolean('is_active')->default(false);
        $table->jsonb('law_types')->default('[]');
        $table->jsonb('regions')->default('[]');
        $table->jsonb('excluded_regions')->default('[]');
        $table->jsonb('okpd2_codes')->default('[]');
        $table->decimal('min_price', 15, 2)->nullable();
        $table->decimal('max_price', 15, 2)->nullable();
        $table->unsignedSmallInteger('deadline_min_days')->default(0);
        $table->unsignedSmallInteger('notification_threshold')->default(60);
        $table->boolean('exclude_on_negative_in_title')->default(false);
        $table->jsonb('scoring_weights')->nullable();
        $table->string('schedule_type')->default('manual');
        $table->string('schedule_cron')->nullable();
        $table->string('timezone')->default('Europe/Moscow');
        $table->timestamp('last_run_at')->nullable();
        $table->timestamp('next_run_at')->nullable()->index();
        $table->timestamps();
        $table->unique(['company_id', 'name']);
    });

    Schema::create('keywords', function (Blueprint $table) {
        $table->id();
        $table->foreignId('search_profile_id')->constrained()->cascadeOnDelete();
        $table->string('value');
        $table->string('type');
        $table->string('origin')->default('manual');
        $table->timestamps();
        $table->unique(['search_profile_id', 'value', 'type']);
    });

    Schema::create('synonyms', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
        $table->string('term');
        $table->jsonb('variants')->default('[]');
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('synonyms');
    Schema::dropIfExists('keywords');
    Schema::dropIfExists('search_profiles');
}
```

- [ ] **Step 4: Модели**

`app/Models/SearchProfile.php`:

```php
<?php

namespace App\Models;

use App\Enums\KeywordType;
use App\Enums\ScheduleType;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchProfile extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'law_types' => 'array',
            'regions' => 'array',
            'excluded_regions' => 'array',
            'okpd2_codes' => 'array',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'deadline_min_days' => 'integer',
            'notification_threshold' => 'integer',
            'exclude_on_negative_in_title' => 'boolean',
            'scoring_weights' => 'array',
            'schedule_type' => ScheduleType::class,
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function canActivate(): bool
    {
        return $this->keywords()
            ->whereIn('type', [KeywordType::Main->value, KeywordType::Exact->value, KeywordType::Broad->value])
            ->exists();
    }
}
```

`app/Models/Keyword.php`:

```php
class Keyword extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => \App\Enums\KeywordType::class, 'origin' => \App\Enums\KeywordOrigin::class];
    }

    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }
}
```

`app/Models/Synonym.php` — `$guarded = ['id']`, cast `variants => 'array'` (без BelongsToCompany: company_id nullable = глобальный словарь).

Фабрики:

```php
// SearchProfileFactory
public function definition(): array
{
    return ['company_id' => Company::factory(), 'name' => fake()->unique()->words(2, true)];
}

// KeywordFactory
public function definition(): array
{
    return ['search_profile_id' => SearchProfile::factory(), 'value' => fake()->word(), 'type' => KeywordType::Main];
}
```

- [ ] **Step 5: Run → PASS** (`php artisan migrate && php artisan test --filter=SearchProfile`)

- [ ] **Step 6: Снять `skip` с тестов Task 5 и прогнать**

Run: `php artisan test --filter=BelongsToCompany`
Expected: PASS (изоляция и автозаполнение работают на SearchProfile).

- [ ] **Step 7: Commit** — `git add -A && git commit -m "A1: search_profiles, keywords, synonyms"`

---

### Task 8: Sources и CompanySources (шифрованный config)

**Files:**
- Create: миграция `*_create_source_tables.php`; модели `Source.php`, `CompanySource.php`; фабрика `SourceFactory.php`; сидер `SourceSeeder.php`
- Test: `tests/Feature/Models/SourceTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

it('сидирует реестр источников', function () {
    $this->seed(\Database\Seeders\SourceSeeder::class);
    expect(Source::pluck('code')->all())->toContain('eis', 'manual');
});

it('шифрует клиентский config', function () {
    $cs = CompanySource::factory()->create(['config' => '{"apiKey":"secret-123"}']);

    $raw = DB::table('company_sources')->where('id', $cs->id)->value('config');
    expect($raw)->not->toContain('secret-123')
        ->and($cs->fresh()->config)->toBe('{"apiKey":"secret-123"}');
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Миграция** `*_create_source_tables.php`:

```php
public function up(): void
{
    Schema::create('sources', function (Blueprint $table) {
        $table->id();
        $table->string('code')->unique();
        $table->string('name');
        $table->string('base_url')->nullable();
        $table->string('connection_type');
        $table->string('adapter_class')->nullable();
        $table->boolean('is_active')->default(true);
        $table->jsonb('default_config')->default('{}');
        $table->string('schedule_cron')->nullable();
        $table->timestamp('last_collected_at')->nullable();
        $table->timestamps();
    });

    Schema::create('company_sources', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('source_id')->constrained()->cascadeOnDelete();
        $table->boolean('is_active')->default(true);
        $table->text('config')->nullable();
        $table->timestamps();
        $table->unique(['company_id', 'source_id']);
    });
}
```

- [ ] **Step 4: Модели, фабрика, сидер**

```php
// app/Models/Source.php
class Source extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'connection_type' => \App\Enums\SourceConnectionType::class,
            'is_active' => 'boolean',
            'default_config' => 'array',
            'last_collected_at' => 'datetime',
        ];
    }
}

// app/Models/CompanySource.php
class CompanySource extends Model
{
    use \App\Tenancy\BelongsToCompany;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'config' => 'encrypted'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
```

`SourceFactory`: `['code' => fake()->unique()->slug(1), 'name' => fake()->company(), 'connection_type' => SourceConnectionType::Manual]`.
`CompanySourceFactory`: `['company_id' => Company::factory(), 'source_id' => Source::factory()]`.

`database/seeders/SourceSeeder.php`:

```php
public function run(): void
{
    foreach ([
        ['code' => 'eis', 'name' => 'ЕИС / zakupki.gov.ru', 'base_url' => 'https://zakupki.gov.ru',
         'connection_type' => 'open_data', 'adapter_class' => null],
        ['code' => 'manual', 'name' => 'Ручной импорт', 'base_url' => null,
         'connection_type' => 'manual', 'adapter_class' => null],
    ] as $source) {
        Source::updateOrCreate(['code' => $source['code']], $source);
    }
}
```

(`adapter_class` для ЕИС заполнит план A6, когда появится класс адаптера.)

- [ ] **Step 5: Run → PASS**, **Step 6: Commit** — `git commit -m "A1: sources, company_sources (encrypted config)"`

---

### Task 9: Tenders (tsvector, pg_trgm, дедуп-индексы)

**Files:**
- Create: миграция `*_create_tenders_table.php`; модель `Tender.php`; фабрика `TenderFactory.php`
- Test: `tests/Feature/Models/TenderTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Source;
use App\Models\Tender;
use Illuminate\Database\QueryException;

it('заполняет search_vector и находит по русской морфологии', function () {
    Tender::factory()->create([
        'title' => 'Поставка металлоконструкций для моста',
        'description' => 'Изготовление по чертежам КМД',
    ]);

    $found = Tender::whereRaw("search_vector @@ plainto_tsquery('russian', ?)", ['металлоконструкции'])->exists();
    expect($found)->toBeTrue();
});

it('запрещает дубль по (source_id, external_id)', function () {
    $source = Source::factory()->create();
    Tender::factory()->create(['source_id' => $source->id, 'external_id' => 'X-1']);
    Tender::factory()->create(['source_id' => $source->id, 'external_id' => 'X-1']);
})->throws(QueryException::class);

it('не позволяет массово назначить search_vector', function () {
    $tender = Tender::factory()->make();
    expect($tender->isGuarded('search_vector'))->toBeTrue();
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Миграция** `*_create_tenders_table.php` (raw SQL — generated-колонка и индексы; точный DDL из спеки §5):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->string('external_id');
            $table->string('number')->nullable();
            $table->string('source_url', 2048);
            $table->text('title');
            $table->text('description')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_inn', 12)->nullable();
            $table->string('region')->nullable()->index();
            $table->decimal('price', 15, 2)->nullable()->index();
            $table->string('currency', 3)->default('RUB');
            $table->string('law_type')->default('other')->index();
            $table->string('procedure_type')->nullable();
            $table->string('source_status')->default('active');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('deadline_at')->nullable()->index();
            $table->jsonb('okpd2')->default('[]');
            $table->jsonb('ktru')->default('[]');
            $table->jsonb('extra')->default('{}');
            $table->string('content_hash', 64)->index();
            $table->foreignId('owner_company_id')->nullable()->index()->constrained('companies')->cascadeOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index('source_url');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tenders ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
              setweight(to_tsvector('russian'::regconfig, coalesce(title, '')), 'A') ||
              setweight(to_tsvector('russian'::regconfig, coalesce(description, '')), 'B')
            ) STORED
        SQL);
        DB::statement('CREATE INDEX tenders_search_vector_gin ON tenders USING GIN (search_vector)');
        DB::statement('CREATE INDEX tenders_title_trgm ON tenders USING GIN (title gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
```

- [ ] **Step 4: Модель и фабрика**

`app/Models/Tender.php`:

```php
<?php

namespace App\Models;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tender extends Model
{
    use HasFactory;

    /** Глобальная таблица: НЕТ BelongsToCompany. Доступ компаний — только через company_tenders (спека §5/§11). */
    protected $guarded = ['id', 'search_vector'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'law_type' => LawType::class,
            'source_status' => TenderSourceStatus::class,
            'published_at' => 'datetime',
            'deadline_at' => 'datetime',
            'okpd2' => 'array',
            'ktru' => 'array',
            'extra' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TenderAttachment::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(TenderChange::class);
    }
}
```

`database/factories/TenderFactory.php`:

```php
public function definition(): array
{
    return [
        'source_id' => Source::factory(),
        'external_id' => fake()->unique()->uuid(),
        'source_url' => fake()->url(),
        'title' => 'Поставка '.fake()->words(3, true),
        'description' => fake()->sentence(12),
        'customer_name' => 'ГБУ «'.fake()->company().'»',
        'region' => 'Москва',
        'price' => fake()->randomFloat(2, 100_000, 50_000_000),
        'law_type' => \App\Enums\LawType::Fz44,
        'source_status' => \App\Enums\TenderSourceStatus::Active,
        'published_at' => now()->subDays(2),
        'deadline_at' => now()->addDays(10),
        'content_hash' => hash('sha256', fake()->unique()->uuid()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ];
}
```

- [ ] **Step 5: Run → PASS** (`php artisan migrate && php artisan test --filter=TenderTest`)

- [ ] **Step 6: Commit** — `git commit -m "A1: tenders (tsvector russian, pg_trgm, дедуп-индексы)"`

---

### Task 10: TenderChanges, TenderAttachments, SourceCollectRuns, RadarRuns

**Files:**
- Create: миграции `*_create_run_tables.php` (runs — ДО tender_changes: FK), `*_create_tender_detail_tables.php`; модели `SourceCollectRun.php`, `RadarRun.php`, `TenderChange.php`, `TenderAttachment.php`
- Test: `tests/Feature/Models/RunsAndChangesTest.php`

**Внимание к порядку:** миграция runs должна иметь timestamp РАНЬШЕ tender_detail (tender_changes ссылается на source_collect_runs), но обе — ПОСЛЕ tenders. `php artisan make:migration` создаёт их в порядке вызова — создавать в указанном порядке.

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\SourceCollectRun;
use App\Models\Tender;

it('пишет лог сбора и diff изменений закупки', function () {
    $tender = Tender::factory()->create();
    $run = SourceCollectRun::create([
        'source_id' => $tender->source_id,
        'trigger' => RunTrigger::Manual,
        'status' => RunStatus::Running,
        'started_at' => now(),
    ]);

    $tender->changes()->create([
        'source_collect_run_id' => $run->id,
        'field' => 'price',
        'old_value' => '100000.00',
        'new_value' => '120000.00',
    ]);

    expect($tender->changes)->toHaveCount(1)
        ->and($run->trigger)->toBe(RunTrigger::Manual);
});

it('radar_run принадлежит профилю и компании', function () {
    $profile = SearchProfile::factory()->create();
    $run = RadarRun::create([
        'company_id' => $profile->company_id,
        'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Ok,
        'started_at' => now(),
    ]);
    expect($run->searchProfile->is($profile))->toBeTrue();
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Миграция** `*_create_run_tables.php`:

```php
public function up(): void
{
    Schema::create('source_collect_runs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('source_id')->constrained();
        $table->string('trigger');
        $table->string('status')->default('running');
        $table->timestamp('started_at');
        $table->timestamp('finished_at')->nullable();
        $table->unsignedInteger('fetched_count')->default(0);
        $table->unsignedInteger('new_count')->default(0);
        $table->unsignedInteger('updated_count')->default(0);
        $table->unsignedInteger('error_count')->default(0);
        $table->text('log')->nullable();
        $table->timestamps();
    });

    Schema::create('radar_runs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('search_profile_id')->constrained()->cascadeOnDelete();
        $table->string('trigger');
        $table->string('status')->default('running');
        $table->timestamp('started_at');
        $table->timestamp('finished_at')->nullable();
        $table->unsignedInteger('processed_count')->default(0);
        $table->unsignedInteger('matched_count')->default(0);
        $table->unsignedInteger('notified_count')->default(0);
        $table->unsignedInteger('error_count')->default(0);
        $table->text('log')->nullable();
        $table->timestamps();
    });
}
```

Миграция `*_create_tender_detail_tables.php`:

```php
public function up(): void
{
    Schema::create('tender_changes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
        $table->foreignId('source_collect_run_id')->nullable()->constrained()->nullOnDelete();
        $table->string('field');
        $table->text('old_value')->nullable();
        $table->text('new_value')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('tender_attachments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
        $table->string('title');
        $table->string('url', 2048);
        $table->string('file_type')->nullable();
        $table->string('local_path')->nullable();
        $table->text('parsed_text')->nullable();
        $table->timestamp('created_at');
    });
}
```

- [ ] **Step 4: Модели** (компактно; все `$guarded = ['id']`):

```php
class SourceCollectRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['trigger' => RunTrigger::class, 'status' => RunStatus::class,
                'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function source(): BelongsTo { return $this->belongsTo(Source::class); }
}

class RadarRun extends Model
{
    use \App\Tenancy\BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['trigger' => RunTrigger::class, 'status' => RunStatus::class,
                'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function searchProfile(): BelongsTo { return $this->belongsTo(SearchProfile::class); }
}

class TenderChange extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
}

class TenderAttachment extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
}
```

(в `TenderChange`/`TenderAttachment` поле `created_at` заполнять при создании: `protected static function booted() { static::creating(fn ($m) => $m->created_at ??= now()); }`.)

- [ ] **Step 5: Run → PASS**, **Step 6: Commit** — `git commit -m "A1: runs (collect/radar), tender_changes, tender_attachments"`

---

### Task 11: CompanyTenders, TenderMatches, комментарии, история статусов

> **Заметки с ревью Task 5 (КРИТИЧНО для A4-матчера):** `Model::insert()/upsert()/mass update()` обходят и global scope, и creating-хук BelongsToCompany — в этих вызовах `company_id` ВСЕГДА передавать явно. Тест «джоб компании А не видит Б» обязан выставлять контекст на границе джоба явно (`set(null)` или company джоба): sync-очередь в тестах НЕ сбрасывает scoped-инстансы, иначе тест проверяет харнесс, а не изоляцию.

**Files:**
- Create: миграция `*_create_company_tender_tables.php`; модели `CompanyTender.php`, `TenderMatch.php`, `TenderComment.php`, `TenderStatusHistory.php`; фабрики `CompanyTenderFactory.php`, `TenderMatchFactory.php`
- Test: `tests/Feature/Models/CompanyTenderTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Models\Company;
use App\Models\CompanyTender;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

it('хранит per-company проекцию закупки и матч с расшифровкой', function () {
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create();
    $tender = Tender::factory()->create();
    app(TenantContext::class)->set($company->id); // matches() полагается на tenant-scope

    $ct = CompanyTender::create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'status' => WorkflowStatus::New,
        'best_score' => 75,
        'best_level' => RelevanceLevel::Medium,
        'first_matched_at' => now(),
    ]);

    TenderMatch::create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'score' => 75,
        'level' => RelevanceLevel::Medium,
        'reason' => [['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'металлоконструкции']],
        'matched_keywords' => [['value' => 'металлоконструкции', 'type' => 'main', 'field' => 'title']],
        'risk_flags' => ['deadline_soon'],
        'action_recommendation' => ActionRecommendation::ReviewDocs,
    ]);

    expect($ct->tender->is($tender))->toBeTrue()
        ->and($ct->matches)->toHaveCount(1)
        ->and($ct->matches->first()->reason[0]['points'])->toBe(20);
});

it('запрещает второй company_tender той же пары', function () {
    $ct = CompanyTender::factory()->create();
    CompanyTender::factory()->create(['company_id' => $ct->company_id, 'tender_id' => $ct->tender_id]);
})->throws(QueryException::class);

it('изолирует company_tenders по тенанту', function () {
    $a = CompanyTender::factory()->create();
    CompanyTender::factory()->create(); // другая компания

    app(TenantContext::class)->set($a->company_id);
    expect(CompanyTender::count())->toBe(1);
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Миграция** `*_create_company_tender_tables.php`:

```php
public function up(): void
{
    Schema::create('company_tenders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
        $table->string('status')->default('new')->index();
        $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->unsignedSmallInteger('best_score')->default(0)->index();
        $table->string('best_level')->default('low')->index();
        $table->timestamp('first_matched_at');
        $table->timestamps();
        $table->unique(['company_id', 'tender_id']);
    });

    Schema::create('tender_matches', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
        $table->foreignId('search_profile_id')->constrained()->cascadeOnDelete();
        $table->foreignId('radar_run_id')->nullable()->constrained()->nullOnDelete();
        $table->unsignedSmallInteger('score');
        $table->string('level');
        $table->jsonb('reason')->default('[]');
        $table->jsonb('matched_keywords')->default('[]');
        $table->jsonb('matched_negative_keywords')->default('[]');
        $table->jsonb('risk_flags')->default('[]');
        $table->string('action_recommendation');
        $table->jsonb('ai_assessment')->nullable();
        $table->timestamps();
        $table->unique(['search_profile_id', 'tender_id']);
    });

    Schema::create('tender_comments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained();
        $table->text('comment');
        $table->timestamp('created_at');
    });

    Schema::create('tender_status_history', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('old_status')->nullable();
        $table->string('new_status');
        $table->timestamp('created_at');
    });
}
```

- [ ] **Step 4: Модели** (все четыре — с `BelongsToCompany`):

```php
class CompanyTender extends Model
{
    use \App\Tenancy\BelongsToCompany;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => WorkflowStatus::class, 'best_level' => RelevanceLevel::class,
                'best_score' => 'integer', 'first_matched_at' => 'datetime'];
    }

    public function tender(): BelongsTo { return $this->belongsTo(Tender::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_user_id'); }

    /**
     * Матчи той же пары (company, tender). ВАЖНО: связь только по tender_id —
     * фильтрацию по company_id даёт tenant-scope TenderMatch (BelongsToCompany).
     * Использовать только при установленном TenantContext (никаких $this->attr
     * внутри relationship — ломает eager loading).
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TenderMatch::class, 'tender_id', 'tender_id');
    }
}

class TenderMatch extends Model
{
    use \App\Tenancy\BelongsToCompany;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['score' => 'integer', 'level' => RelevanceLevel::class,
                'reason' => 'array', 'matched_keywords' => 'array', 'matched_negative_keywords' => 'array',
                'risk_flags' => 'array', 'action_recommendation' => ActionRecommendation::class,
                'ai_assessment' => 'array'];
    }

    public function tender(): BelongsTo { return $this->belongsTo(Tender::class); }
    public function searchProfile(): BelongsTo { return $this->belongsTo(SearchProfile::class); }
}

class TenderComment extends Model
{
    use \App\Tenancy\BelongsToCompany;

    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    protected static function booted() { static::creating(fn ($m) => $m->created_at ??= now()); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}

class TenderStatusHistory extends Model
{
    use \App\Tenancy\BelongsToCompany;

    public $timestamps = false;
    protected $table = 'tender_status_history';
    protected $guarded = ['id'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    protected static function booted() { static::creating(fn ($m) => $m->created_at ??= now()); }
}
```

Фабрики:

```php
// CompanyTenderFactory
public function definition(): array
{
    return [
        'company_id' => Company::factory(),
        'tender_id' => Tender::factory(),
        'status' => WorkflowStatus::New,
        'best_score' => 0,
        'best_level' => RelevanceLevel::Low,
        'first_matched_at' => now(),
    ];
}

// TenderMatchFactory
public function definition(): array
{
    return [
        'company_id' => Company::factory(),
        'tender_id' => Tender::factory(),
        'search_profile_id' => SearchProfile::factory(),
        'score' => 60,
        'level' => RelevanceLevel::Medium,
        'action_recommendation' => ActionRecommendation::ReviewDocs,
    ];
}
```

- [ ] **Step 5: Run → PASS**, **Step 6: Commit** — `git commit -m "A1: company_tenders, tender_matches, комментарии, история статусов"`

---

### Task 12: NotificationLogs, AiSummaries, AiUsage, AuditLogs

**Files:**
- Create: миграция `*_create_log_tables.php`; модели `NotificationLog.php`, `AiSummary.php`, `AiUsage.php`, `AuditLog.php`
- Test: `tests/Feature/Models/LogTablesTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\AiUsage;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Tender;
use Illuminate\Database\QueryException;

it('логирует уведомление с каналом web', function () {
    $log = NotificationLog::create([
        'company_id' => Company::factory()->create()->id,
        'tender_id' => Tender::factory()->create()->id,
        'channel' => NotificationChannel::Web,
        'recipient' => 'user:1',
        'status' => NotificationStatus::Queued,
        'payload' => ['score' => 80],
    ]);
    expect($log->channel)->toBe(NotificationChannel::Web);
});

it('ai_usage уникален по компании и периоду', function () {
    $company = Company::factory()->create();
    AiUsage::create(['company_id' => $company->id, 'period' => '2026-06', 'calls' => 1, 'tokens_used' => 100]);
    AiUsage::create(['company_id' => $company->id, 'period' => '2026-06', 'calls' => 2, 'tokens_used' => 200]);
})->throws(QueryException::class);
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Миграция** `*_create_log_tables.php`:

```php
public function up(): void
{
    Schema::create('notification_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('tender_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('search_profile_id')->nullable()->constrained()->nullOnDelete();
        $table->string('channel');
        $table->string('recipient');
        $table->string('status')->default('queued');
        $table->jsonb('payload')->default('{}');
        $table->timestamp('sent_at')->nullable();
        $table->text('error')->nullable();
        $table->timestamps();
        $table->index(['company_id', 'tender_id', 'search_profile_id', 'channel'], 'notification_dedup_idx');
    });

    Schema::create('ai_summaries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tender_id')->unique()->constrained()->cascadeOnDelete();
        $table->string('provider');
        $table->string('model');
        $table->text('summary');
        $table->jsonb('requirements')->default('[]');
        $table->jsonb('risks')->default('[]');
        $table->unsignedInteger('tokens_used')->default(0);
        $table->timestamp('created_at');
    });

    Schema::create('ai_usage', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->string('period', 7); // YYYY-MM
        $table->unsignedInteger('calls')->default(0);
        $table->unsignedBigInteger('tokens_used')->default(0);
        $table->timestamps();
        $table->unique(['company_id', 'period']);
    });

    Schema::create('audit_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('action');
        $table->string('entity_type')->nullable();
        $table->unsignedBigInteger('entity_id')->nullable();
        $table->jsonb('payload')->default('{}');
        $table->string('ip', 45)->nullable();
        $table->timestamp('created_at');
        $table->index(['entity_type', 'entity_id']);
    });
}
```

- [ ] **Step 4: Модели**

```php
class NotificationLog extends Model
{
    use \App\Tenancy\BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['channel' => NotificationChannel::class, 'status' => NotificationStatus::class,
                'payload' => 'array', 'sent_at' => 'datetime'];
    }

    public function tender(): BelongsTo { return $this->belongsTo(Tender::class); }
}

class AiSummary extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['requirements' => 'array', 'risks' => 'array', 'created_at' => 'datetime'];
    }
    protected static function booted() { static::creating(fn ($m) => $m->created_at ??= now()); }
}

class AiUsage extends Model
{
    use \App\Tenancy\BelongsToCompany;

    protected $table = 'ai_usage';
    protected $guarded = ['id'];
}

class AuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['payload' => 'array', 'created_at' => 'datetime']; }
    protected static function booted() { static::creating(fn ($m) => $m->created_at ??= now()); }
}
```

(`AuditLog` — без `BelongsToCompany`: company_id nullable, действия суперадмина глобальны.)

- [ ] **Step 5: Run → PASS**, **Step 6: Commit** — `git commit -m "A1: notification_logs, ai_summaries, ai_usage, audit_logs"`

---

### Task 13: Демо-сидер и auth login-only

> **Заметки с ревью Task 3:** вместе с регистрацией удалить весь verification-флоу (продукт login-only, User не реализует MustVerifyEmail): контроллеры `EmailVerificationPromptController`, `EmailVerificationNotificationController`, `VerifyEmailController`, их маршруты в routes/auth.php, Vue-страницу VerifyEmail и middleware `verified` с маршрута dashboard.

**Files:**
- Create: `database/seeders/DemoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`, `routes/auth.php` (или маршруты регистрации стартер-кита), Vue-страница логина (убрать ссылку на регистрацию)
- Test: `tests/Feature/Auth/LoginOnlyTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\User;

it('регистрация недоступна', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('логин работает и ведёт на дашборд', function () {
    $user = User::factory()->create(['password' => 'password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
```

- [ ] **Step 2: Run → FAIL** (register доступен)

- [ ] **Step 3: Убрать регистрацию**

В `routes/auth.php` удалить маршруты `register` (GET и POST) и соответствующий контроллер-импорт; из Vue-страницы логина удалить ссылку «Sign up / Register». Удалить тесты стартер-кита `tests/Feature/Auth/RegistrationTest.php`.

- [ ] **Step 4: DemoSeeder**

```php
<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, SourceSeeder::class]);

        $company = Company::updateOrCreate(
            ['inn' => '7700000000'],
            ['name' => 'ООО «Демо Металл»', 'description' => 'Изготовление и монтаж металлоконструкций',
             'notification_email' => 'demo@tender.local', 'is_active' => true],
        );

        $company->subscriptions()->firstOrCreate(
            ['plan_id' => Plan::where('code', 'basic')->value('id')],
            ['status' => SubscriptionStatus::Active, 'started_at' => now()],
        );

        foreach (Source::whereIn('code', ['eis', 'manual'])->get() as $source) {
            \App\Models\CompanySource::firstOrCreate(
                ['company_id' => $company->id, 'source_id' => $source->id],
            );
        }

        User::updateOrCreate(
            ['email' => 'admin@tender.local'],
            ['name' => 'Админ', 'password' => 'password', 'role' => UserRole::Admin, 'company_id' => $company->id],
        );
        User::updateOrCreate(
            ['email' => 'manager@tender.local'],
            ['name' => 'Менеджер', 'password' => 'password', 'role' => UserRole::Manager, 'company_id' => $company->id],
        );
        User::updateOrCreate(
            ['email' => 'root@tender.local'],
            ['name' => 'Суперадмин', 'password' => 'password', 'role' => UserRole::Superadmin, 'company_id' => null],
        );
    }
}
```

`DatabaseSeeder::run()`: `$this->call(DemoSeeder::class);`

- [ ] **Step 5: Run → PASS**

Run: `php artisan migrate:fresh --seed && php artisan test`
Expected: сидеры отрабатывают, все тесты PASS.

- [ ] **Step 6: Commit** — `git commit -m "A1: демо-сидер, auth login-only"`

---

### Task 14: Финальная проверка A1

- [ ] **Step 1: Полный прогон**

Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && php artisan test`
Expected: всё PASS. Если pint находит стиль — `vendor/bin/pint` и перепрогнать.

- [ ] **Step 2: Ручная проверка**

Run: `php artisan serve` → открыть `http://localhost:8000/login`, войти `admin@tender.local` / `password`.
Expected: дашборд стартер-кита открывается.

- [ ] **Step 3: Commit оставшегося + пометить план выполненным**

```bash
git add -A && git commit -m "A1: финальная проверка (pint, phpstan, tests)"
```

В `docs/superpowers/plans/2026-06-05-stage-a-roadmap.md` пометить A1 как «выполнен».

---

## Definition of Done (A1)

- `docker compose -f docker-compose.dev.yml up -d` + `php artisan migrate:fresh --seed` отрабатывают с нуля.
- Все 22 таблицы схемы спеки §5 существуют; `tenders.search_vector` — generated, GIN + trigram индексы стоят.
- Тесты: enum'ы, tenant-изоляция (включая автозаполнение company_id), encrypted config, FTS-морфология, уникальные констрейнты, login-only auth — зелёные.
- `pint --test`, `phpstan analyse` — чистые; CI-workflow в репо.
