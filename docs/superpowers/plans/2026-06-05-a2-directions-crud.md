# A2: CRUD направлений, ключей, источников и настроек компании — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Админ компании через UI заводит направления поиска с ключами 6 типов, активирует их, включает источники и редактирует профиль компании — с энфорсом тарифных лимитов и tenant-изоляцией.

**Architecture:** Только app-слой (схема БД готова в A1). Inertia-страницы поверх Breeze-компонентов; авторизация — Gate `manage-company` (только admin с company_id); лимиты тарифа — в FormRequest::after; изоляция — существующий tenant-scope (чужие записи = 404 через route binding).

**Tech Stack:** Laravel 13, Inertia + Vue 3 + Tailwind (Breeze-компоненты), Pest, русская локаль (laravel-lang).

**Спека:** `docs/superpowers/specs/2026-06-05-tender-radar-design.md` §5 (search_profiles/keywords/company_sources), §10 (UI «Направления», «Источники», «Настройки»), §11 (роли: настройки — только admin).

**Конвенции исполнения:** дефолтный `php` на машине теперь 8.3 — обычные `php artisan`/`vendor/bin/*` работают. Ветка: `a2-directions-crud`. После КАЖДОЙ задачи: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `npm run build` (если трогались Vue/JS), коммит.

**Вне scope A2:** расписания профилей (этап B, планировщик), scoring_weights UI (продвинутая настройка — позже), клиентские API-ключи источников (`company_sources.config` — нужен только для платных агрегаторов, этап B), кабинет суперадмина, регистрация компаний. Регионы/ОКПД2 — свободный строковый ввод (справочники — отдельная задача; матчер A4 работает по строкам).

---

### Task 1: Русская локаль

**Files:**
- Modify: `composer.json` (laravel-lang), `.env`, `.env.example`, `config/app.php` (faker), `phpunit.xml`
- Create: `lang/ru/*` (публикуются пакетом)

- [ ] **Step 1: Установить переводы**

```bash
git checkout -b a2-directions-crud
composer require laravel-lang/common --dev
php artisan lang:add ru
```

Expected: появилась папка `lang/ru` (+ `lang/ru.json`) с переводами валидации/auth.

- [ ] **Step 2: Включить локаль**

В `.env` и `.env.example`: `APP_LOCALE=ru`, `APP_FAKER_LOCALE=ru_RU` (строки уже есть — заменить значения). В `phpunit.xml` добавить `<env name="APP_LOCALE" value="ru"/>` (тесты идут на той же локали, что прод).

- [ ] **Step 3: Тест локали** — `tests/Feature/LocaleTest.php`:

```php
<?php

it('валидационные сообщения на русском', function () {
    $response = $this->post('/login', ['email' => 'not-an-email', 'password' => '']);

    $response->assertSessionHasErrors(['email', 'password']);
    expect(session('errors')->first('password'))->toContain('обязательно');
});
```

- [ ] **Step 4: Прогнать всё**

Run: `php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress`
Expected: 57 passed (56 + 1). Если стартовые Breeze-тесты завязаны на английские строки — поправить тесты на проверку ключей ошибок, не текстов (отметить в отчёте).

- [ ] **Step 5: Commit** — `git add -A && git commit -m "A2: русская локаль (laravel-lang, faker ru_RU)"`

---

### Task 2: Gate manage-company и share прав в Inertia

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/Authz/ManageCompanyGateTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('gate manage-company пускает только админа компании', function (UserRole $role, bool $allowed) {
    $user = User::factory()->create(['role' => $role]);
    expect(Gate::forUser($user)->allows('manage-company'))->toBe($allowed);
})->with([
    [UserRole::Admin, true],
    [UserRole::Manager, false],
    [UserRole::Viewer, false],
]);

it('суперадмин без компании не управляет кабинетом компании', function () {
    $root = User::factory()->superadmin()->create();
    expect(Gate::forUser($root)->allows('manage-company'))->toBeFalse();
});
```

- [ ] **Step 2: Run → FAIL** (`php artisan test --filter=ManageCompanyGate`)

- [ ] **Step 3: Gate** — в `AppServiceProvider::boot()`:

```php
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('manage-company', fn (User $user): bool => $user->role === UserRole::Admin && $user->company_id !== null);
```

(Кабинет компании суперадмину недоступен — у него нет тенанта; его админка — этап B.)

- [ ] **Step 4: Share прав** — в `HandleInertiaRequests::share()` внутри массива `auth`:

```php
'auth' => [
    'user' => $request->user(),
    'can' => [
        'manageCompany' => (bool) $request->user()?->can('manage-company'),
    ],
],
```

- [ ] **Step 5: Run → PASS**, полный прогон, **Commit** — `git commit -m "A2: gate manage-company + share прав в Inertia"`

---

### Task 3: Русская навигация кабинета

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

- [ ] **Step 1: Добавить пункты меню**

В desktop-блоке NavLink'ов (рядом с Dashboard) и в responsive-блоке:

```vue
<NavLink :href="route('dashboard')" :active="route().current('dashboard')">
    Дашборд
</NavLink>
<template v-if="$page.props.auth.can.manageCompany">
    <NavLink :href="route('profiles.index')" :active="route().current('profiles.*')">
        Направления
    </NavLink>
    <NavLink :href="route('sources.index')" :active="route().current('sources.*')">
        Источники
    </NavLink>
    <NavLink :href="route('company.edit')" :active="route().current('company.*')">
        Компания
    </NavLink>
</template>
```

(то же для `ResponsiveNavLink`; подпись Dashboard → «Дашборд», Profile → «Профиль», Log Out → «Выйти» в dropdown.)

ВНИМАНИЕ: маршруты `profiles.index`/`sources.index`/`company.edit` появятся в Task 4/8/9 — Ziggy `route()` упадёт на несуществующем имени при рендере. Поэтому в ЭТОЙ задаче добавить только переименования (Дашборд/Профиль/Выйти) и заготовить блок закомментированным, а раскомментировать пункты в задачах 5/8/9 по мере появления маршрутов. Зафиксируй это в отчёте.

- [ ] **Step 2: Build + тесты** — `npm run build && php artisan test`

- [ ] **Step 3: Commit** — `git commit -m "A2: русские подписи навигации"`

---

### Task 4: SearchProfile CRUD — backend

**Files:**
- Create: `app/Http/Controllers/SearchProfileController.php`, `app/Http/Requests/SearchProfileRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Profiles/SearchProfileCrudTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\Company;
use App\Models\Plan;
use App\Models\SearchProfile;
use App\Models\Subscription;
use App\Models\User;

function adminWithPlan(int $maxProfiles = 3): User
{
    $company = Company::factory()->create();
    $plan = Plan::factory()->create(['max_search_profiles' => $maxProfiles]);
    Subscription::factory()->for($company)->create(['plan_id' => $plan->id]);

    return User::factory()->for($company)->create(['role' => \App\Enums\UserRole::Admin]);
}

it('админ создаёт направление', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->post(route('profiles.store'), [
            'name' => 'Металлоконструкции',
            'law_types' => ['fz44', 'fz223'],
            'regions' => ['Москва'],
            'excluded_regions' => [],
            'okpd2_codes' => [],
            'min_price' => 500000,
            'max_price' => null,
            'deadline_min_days' => 3,
            'notification_threshold' => 60,
            'exclude_on_negative_in_title' => false,
        ])
        ->assertRedirect();

    expect(SearchProfile::withoutTenancy()->where('company_id', $admin->company_id)->where('name', 'Металлоконструкции')->exists())->toBeTrue();
});

it('менеджеру запрещено', function () {
    $admin = adminWithPlan();
    $manager = User::factory()->for($admin->company)->create(['role' => \App\Enums\UserRole::Manager]);

    $this->actingAs($manager)->get(route('profiles.index'))->assertForbidden();
    $this->actingAs($manager)->post(route('profiles.store'), ['name' => 'X'])->assertForbidden();
});

it('лимит тарифа на число направлений', function () {
    $admin = adminWithPlan(maxProfiles: 1);
    SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->post(route('profiles.store'), ['name' => 'Второе', 'law_types' => [], 'regions' => [], 'excluded_regions' => [], 'okpd2_codes' => [], 'deadline_min_days' => 0, 'notification_threshold' => 60, 'exclude_on_negative_in_title' => false])
        ->assertSessionHasErrors('name');
});

it('без активной подписки создание запрещено', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->for($company)->create(['role' => \App\Enums\UserRole::Admin]);

    $this->actingAs($admin)
        ->post(route('profiles.store'), ['name' => 'X', 'law_types' => [], 'regions' => [], 'excluded_regions' => [], 'okpd2_codes' => [], 'deadline_min_days' => 0, 'notification_threshold' => 60, 'exclude_on_negative_in_title' => false])
        ->assertSessionHasErrors('name');
});

it('чужое направление недоступно (404)', function () {
    $admin = adminWithPlan();
    $foreign = SearchProfile::factory()->create(); // другая компания

    $this->actingAs($admin)->get(route('profiles.edit', $foreign))->assertNotFound();
    $this->actingAs($admin)->delete(route('profiles.destroy', $foreign))->assertNotFound();
});

it('обновление и удаление своего направления', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->patch(route('profiles.update', $profile), [
            'name' => 'Новое имя',
            'law_types' => ['commercial'],
            'regions' => [],
            'excluded_regions' => ['Чукотка'],
            'okpd2_codes' => ['25.11'],
            'min_price' => null,
            'max_price' => 1000000,
            'deadline_min_days' => 5,
            'notification_threshold' => 70,
            'exclude_on_negative_in_title' => true,
        ])
        ->assertRedirect(route('profiles.edit', $profile, absolute: false));

    $profile->refresh();
    expect($profile->name)->toBe('Новое имя')
        ->and($profile->excluded_regions)->toBe(['Чукотка'])
        ->and($profile->notification_threshold)->toBe(70);

    $this->actingAs($admin)->delete(route('profiles.destroy', $profile))->assertRedirect(route('profiles.index', absolute: false));
    expect(SearchProfile::withoutTenancy()->find($profile->id))->toBeNull();
});

it('валидация: max_price не меньше min_price, порог 0-100', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->post(route('profiles.store'), [
            'name' => 'X', 'law_types' => [], 'regions' => [], 'excluded_regions' => [], 'okpd2_codes' => [],
            'min_price' => 100, 'max_price' => 50,
            'deadline_min_days' => 0, 'notification_threshold' => 150, 'exclude_on_negative_in_title' => false,
        ])
        ->assertSessionHasErrors(['max_price', 'notification_threshold']);
});
```

- [ ] **Step 2: Run → FAIL** (маршрутов нет)

- [ ] **Step 3: FormRequest** — `app/Http/Requests/SearchProfileRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\LawType;
use App\Models\SearchProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-company');
    }

    protected function prepareForValidation(): void
    {
        // '' из числовых инпутов → null
        $this->merge([
            'min_price' => $this->input('min_price') === '' ? null : $this->input('min_price'),
            'max_price' => $this->input('max_price') === '' ? null : $this->input('max_price'),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('search_profiles', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($this->route('profile')),
            ],
            'law_types' => ['array'],
            'law_types.*' => [Rule::enum(LawType::class)],
            'regions' => ['array'],
            'regions.*' => ['string', 'max:255'],
            'excluded_regions' => ['array'],
            'excluded_regions.*' => ['string', 'max:255'],
            'okpd2_codes' => ['array'],
            'okpd2_codes.*' => ['string', 'max:20'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'gte:min_price'],
            'deadline_min_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notification_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'exclude_on_negative_in_title' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->route('profile') !== null) {
                    return; // лимит — только на создание
                }

                $company = $this->user()->company;
                $plan = $company->activeSubscription()?->plan;

                if ($plan === null) {
                    $validator->errors()->add('name', 'У компании нет активной подписки — создание направлений недоступно.');

                    return;
                }

                $count = SearchProfile::query()->count(); // tenant-scope уже ограничил компанией
                if ($count >= $plan->max_search_profiles) {
                    $validator->errors()->add('name', "Достигнут лимит тарифа: {$plan->max_search_profiles} направлений.");
                }
            },
        ];
    }
}
```

- [ ] **Step 4: Controller** — `app/Http/Controllers/SearchProfileController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchProfileRequest;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SearchProfileController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Profiles/Index', [
            'profiles' => SearchProfile::query()
                ->withCount('keywords')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Profiles/Create');
    }

    public function store(SearchProfileRequest $request): RedirectResponse
    {
        $profile = SearchProfile::create($request->validated());

        return redirect()
            ->route('profiles.edit', $profile)
            ->with('success', 'Направление создано. Добавьте ключевые слова и активируйте его.');
    }

    public function edit(SearchProfile $profile): Response
    {
        return Inertia::render('Profiles/Edit', [
            'profile' => $profile,
            'keywords' => $profile->keywords()->orderBy('type')->orderBy('value')->get(),
            'canActivate' => $profile->canActivate(),
        ]);
    }

    public function update(SearchProfileRequest $request, SearchProfile $profile): RedirectResponse
    {
        $profile->update($request->validated());

        return redirect()->route('profiles.edit', $profile)->with('success', 'Сохранено.');
    }

    public function destroy(SearchProfile $profile): RedirectResponse
    {
        $profile->delete();

        return redirect()->route('profiles.index')->with('success', 'Направление удалено.');
    }
}
```

- [ ] **Step 5: Routes** — в `routes/web.php` (после profile-группы):

```php
use App\Http\Controllers\SearchProfileController;

Route::middleware(['auth', 'can:manage-company'])->group(function () {
    Route::resource('profiles', SearchProfileController::class)
        ->except(['show'])
        ->parameters(['profiles' => 'profile']);
});
```

- [ ] **Step 6: Run → PASS** (контроллер вернёт Inertia-страницы, которых ещё нет — но тесты бэкенда страницы не рендерят клиентом; Inertia вернёт 200 с компонентом «Profiles/Index» — фронт появится в Task 5). Полный прогон + pint + phpstan.

- [ ] **Step 7: Commit** — `git commit -m "A2: SearchProfile CRUD backend (лимиты тарифа, изоляция)"`

---

### Task 5: SearchProfile CRUD — frontend

**Files:**
- Create: `resources/js/Components/TagInput.vue`, `resources/js/Pages/Profiles/Index.vue`, `resources/js/Pages/Profiles/Create.vue`, `resources/js/Pages/Profiles/Edit.vue`, `resources/js/Pages/Profiles/Partials/ProfileForm.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (раскомментировать пункт «Направления»)
- Test: `tests/Feature/Profiles/SearchProfilePagesTest.php`

- [ ] **Step 1: Failing test (Inertia-компоненты и props)**

```php
<?php

use App\Models\SearchProfile;
use Inertia\Testing\AssertableInertia as Assert;

it('страница списка отдаёт направления с количеством ключей', function () {
    $admin = adminWithPlan();
    SearchProfile::factory()->create(['company_id' => $admin->company_id, 'name' => 'Резка металла']);

    $this->actingAs($admin)
        ->get(route('profiles.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profiles/Index')
            ->has('profiles', 1)
            ->where('profiles.0.name', 'Резка металла')
            ->has('profiles.0.keywords_count'));
});

it('страница редактирования отдаёт профиль, ключи и флаг активации', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->get(route('profiles.edit', $profile))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profiles/Edit')
            ->where('profile.id', $profile->id)
            ->has('keywords')
            ->where('canActivate', false));
});
```

(helper `adminWithPlan` уже в SearchProfileCrudTest — вынести его в `tests/Pest.php` как глобальную функцию, чтобы оба файла использовали; перенос отметить.)

- [ ] **Step 2: Run → FAIL** (нет глобального helper'а — после переноса PASS бэкенд-часть; компонентов ещё нет, но Inertia-тест проверяет имя компонента, не файл — пройдёт. Поэтому критерий FAIL здесь мягкий: главное — тесты зелёные ПОСЛЕ полной реализации, а `npm run build` без файлов упадёт при рендере. Сначала тест, затем файлы.)

- [ ] **Step 3: TagInput** — `resources/js/Components/TagInput.vue`:

```vue
<script setup>
import { ref } from 'vue';

const model = defineModel({ type: Array, default: () => [] });
defineProps({ placeholder: { type: String, default: 'Введите и нажмите Enter' } });

const input = ref('');

const add = () => {
    const value = input.value.trim();
    if (value && !model.value.includes(value)) {
        model.value = [...model.value, value];
    }
    input.value = '';
};

const remove = (index) => {
    model.value = model.value.filter((_, i) => i !== index);
};
</script>

<template>
    <div>
        <div v-if="model.length" class="mb-2 flex flex-wrap gap-2">
            <span
                v-for="(tag, i) in model"
                :key="tag"
                class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-sm text-gray-800"
            >
                {{ tag }}
                <button type="button" class="text-gray-400 hover:text-red-600" @click="remove(i)">×</button>
            </span>
        </div>
        <input
            v-model="input"
            type="text"
            :placeholder="placeholder"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            @keydown.enter.prevent="add"
            @blur="add"
        />
    </div>
</template>
```

- [ ] **Step 4: ProfileForm (общая форма create/edit)** — `resources/js/Pages/Profiles/Partials/ProfileForm.vue`:

```vue
<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TagInput from '@/Components/TagInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    profile: { type: Object, default: null },
});

const LAW_TYPES = [
    { value: 'fz44', label: '44-ФЗ' },
    { value: 'fz223', label: '223-ФЗ' },
    { value: 'commercial', label: 'Коммерческие' },
];

const form = useForm({
    name: props.profile?.name ?? '',
    law_types: props.profile?.law_types ?? [],
    regions: props.profile?.regions ?? [],
    excluded_regions: props.profile?.excluded_regions ?? [],
    okpd2_codes: props.profile?.okpd2_codes ?? [],
    min_price: props.profile?.min_price ?? null,
    max_price: props.profile?.max_price ?? null,
    deadline_min_days: props.profile?.deadline_min_days ?? 0,
    notification_threshold: props.profile?.notification_threshold ?? 60,
    exclude_on_negative_in_title: props.profile?.exclude_on_negative_in_title ?? false,
});

const submit = () => {
    if (props.profile) {
        form.patch(route('profiles.update', props.profile.id), { preserveScroll: true });
    } else {
        form.post(route('profiles.store'));
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div>
            <InputLabel for="name" value="Название направления" />
            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
            <InputError class="mt-2" :message="form.errors.name" />
        </div>

        <div>
            <InputLabel value="Типы закупок (пусто — любые)" />
            <div class="mt-2 flex gap-6">
                <label v-for="law in LAW_TYPES" :key="law.value" class="flex items-center gap-2">
                    <Checkbox v-model:checked="form.law_types" :value="law.value" />
                    <span class="text-sm text-gray-700">{{ law.label }}</span>
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.law_types" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <InputLabel value="Приоритетные регионы (+10 к баллу)" />
                <TagInput v-model="form.regions" class="mt-1" placeholder="Москва" />
                <InputError class="mt-2" :message="form.errors.regions" />
            </div>
            <div>
                <InputLabel value="Исключённые регионы (жёсткий фильтр)" />
                <TagInput v-model="form.excluded_regions" class="mt-1" />
                <InputError class="mt-2" :message="form.errors.excluded_regions" />
            </div>
        </div>

        <div>
            <InputLabel value="Коды ОКПД2 (+5 к баллу, префиксное совпадение)" />
            <TagInput v-model="form.okpd2_codes" class="mt-1" placeholder="25.11" />
            <InputError class="mt-2" :message="form.errors.okpd2_codes" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <InputLabel for="min_price" value="Минимальная сумма, ₽" />
                <TextInput id="min_price" v-model="form.min_price" type="number" min="0" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.min_price" />
            </div>
            <div>
                <InputLabel for="max_price" value="Максимальная сумма, ₽" />
                <TextInput id="max_price" v-model="form.max_price" type="number" min="0" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.max_price" />
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <InputLabel for="deadline_min_days" value="Мин. дней до дедлайна" />
                <TextInput id="deadline_min_days" v-model="form.deadline_min_days" type="number" min="0" max="365" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.deadline_min_days" />
            </div>
            <div>
                <InputLabel for="notification_threshold" value="Порог уведомлений (балл ≥)" />
                <TextInput id="notification_threshold" v-model="form.notification_threshold" type="number" min="0" max="100" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.notification_threshold" />
            </div>
        </div>

        <label class="flex items-center gap-2">
            <Checkbox v-model:checked="form.exclude_on_negative_in_title" />
            <span class="text-sm text-gray-700">Полностью исключать закупку при минус-слове в названии</span>
        </label>
        <InputError class="mt-2" :message="form.errors.exclude_on_negative_in_title" />

        <div class="flex items-center gap-4">
            <PrimaryButton :disabled="form.processing">
                {{ profile ? 'Сохранить' : 'Создать направление' }}
            </PrimaryButton>
            <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0">
                <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Сохранено.</p>
            </Transition>
        </div>
    </form>
</template>
```

ВНИМАНИЕ к Checkbox + массиву: Breeze-Checkbox поддерживает `:value` с массивом в `checked` (proxy на нативный input). Если v-model:checked с массивом не работает в текущей версии компонента — заменить на нативный `<input type="checkbox" v-model="form.law_types" :value="law.value">` с теми же классами. Проверить фактически.

- [ ] **Step 5: Index** — `resources/js/Pages/Profiles/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({ profiles: Array });

const destroy = (profile) => {
    if (confirm(`Удалить направление «${profile.name}»? Ключевые слова будут удалены.`)) {
        router.delete(route('profiles.destroy', profile.id));
    }
};
</script>

<template>
    <Head title="Направления" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Направления поиска</h2>
                <Link :href="route('profiles.create')">
                    <PrimaryButton>Добавить направление</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <p v-if="!profiles.length" class="p-6 text-gray-500">
                        Направлений пока нет. Создайте первое — это набор ключевых слов и фильтров,
                        по которым радар ищет закупки.
                    </p>

                    <table v-else class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Название</th>
                                <th class="px-6 py-3">Статус</th>
                                <th class="px-6 py-3">Ключей</th>
                                <th class="px-6 py-3">Порог</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white text-sm">
                            <tr v-for="profile in profiles" :key="profile.id">
                                <td class="px-6 py-4 font-medium text-gray-900">
                                    <Link :href="route('profiles.edit', profile.id)" class="hover:underline">
                                        {{ profile.name }}
                                    </Link>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="profile.is_active
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-gray-100 text-gray-600'"
                                        class="rounded px-2 py-1 text-xs font-medium"
                                    >
                                        {{ profile.is_active ? 'Активно' : 'Выключено' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">{{ profile.keywords_count }}</td>
                                <td class="px-6 py-4">{{ profile.notification_threshold }}</td>
                                <td class="px-6 py-4 text-right">
                                    <button class="text-sm text-red-600 hover:underline" @click="destroy(profile)">
                                        Удалить
                                    </button>
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

- [ ] **Step 6: Create и Edit**

`resources/js/Pages/Profiles/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProfileForm from './Partials/ProfileForm.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Новое направление" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Новое направление</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <ProfileForm />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

`resources/js/Pages/Profiles/Edit.vue` (секция ключей появится в Task 6 — пока заглушка-комментарий, активация — в Task 7):

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ProfileForm from './Partials/ProfileForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    profile: Object,
    keywords: Array,
    canActivate: Boolean,
});
</script>

<template>
    <Head :title="`Направление: ${profile.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ profile.name }}</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <ProfileForm :profile="profile" />
                </div>
                <!-- Task 6: KeywordManager -->
                <!-- Task 7: ActivationPanel -->
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Раскомментировать пункт «Направления»** в AuthenticatedLayout (desktop + responsive).

- [ ] **Step 8: Build + все тесты** — `npm run build && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress`

- [ ] **Step 9: Commit** — `git commit -m "A2: UI направлений (список, создание, редактирование)"`

---

### Task 6: Ключевые слова — backend + UI

**Files:**
- Create: `app/Http/Controllers/KeywordController.php`, `resources/js/Pages/Profiles/Partials/KeywordManager.vue`
- Modify: `routes/web.php`, `resources/js/Pages/Profiles/Edit.vue`
- Test: `tests/Feature/Profiles/KeywordTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;

it('добавляет и удаляет ключи', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'металлоконструкции', 'type' => 'main'])
        ->assertRedirect();

    $keyword = $profile->keywords()->first();
    expect($keyword->value)->toBe('металлоконструкции')
        ->and($keyword->type)->toBe(KeywordType::Main);

    $this->actingAs($admin)->delete(route('keywords.destroy', $keyword))->assertRedirect();
    expect($profile->keywords()->count())->toBe(0);
});

it('дубль ключа того же типа отклоняется валидацией', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->create(['search_profile_id' => $profile->id, 'value' => 'кран', 'type' => KeywordType::Main]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'кран', 'type' => 'main'])
        ->assertSessionHasErrors('value');
});

it('чужой профиль и чужой ключ — 404', function () {
    $admin = adminWithPlan();
    $foreignProfile = SearchProfile::factory()->create();
    $foreignKeyword = Keyword::factory()->create(['search_profile_id' => $foreignProfile->id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $foreignProfile), ['value' => 'x', 'type' => 'main'])
        ->assertNotFound();

    $this->actingAs($admin)->delete(route('keywords.destroy', $foreignKeyword))->assertNotFound();
});

it('неизвестный тип отклоняется', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)
        ->post(route('profiles.keywords.store', $profile), ['value' => 'x', 'type' => 'wrong'])
        ->assertSessionHasErrors('type');
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Controller** — `app/Http/Controllers/KeywordController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KeywordController extends Controller
{
    public function store(Request $request, SearchProfile $profile): RedirectResponse
    {
        $validated = $request->validate([
            'value' => [
                'required', 'string', 'max:255',
                Rule::unique('keywords', 'value')
                    ->where('search_profile_id', $profile->id)
                    ->where('type', $request->input('type')),
            ],
            'type' => ['required', Rule::enum(KeywordType::class)],
        ]);

        $profile->keywords()->create($validated);

        return back()->with('success', 'Ключ добавлен.');
    }

    public function destroy(Keyword $keyword): RedirectResponse
    {
        // Keyword не несёт company_id: принадлежность проверяем через
        // tenant-scope профиля (чужой профиль → null → 404).
        abort_if($keyword->searchProfile()->first() === null, 404);

        $keyword->delete();

        return back()->with('success', 'Ключ удалён.');
    }
}
```

- [ ] **Step 4: Routes** — внутрь группы `can:manage-company`:

```php
Route::post('profiles/{profile}/keywords', [KeywordController::class, 'store'])->name('profiles.keywords.store');
Route::delete('keywords/{keyword}', [KeywordController::class, 'destroy'])->name('keywords.destroy');
```

- [ ] **Step 5: KeywordManager** — `resources/js/Pages/Profiles/Partials/KeywordManager.vue`:

```vue
<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profileId: Number,
    keywords: Array,
});

const TYPES = [
    { value: 'main', label: 'Основные', hint: '+20 в названии / +15 в описании' },
    { value: 'exact', label: 'Точные фразы', hint: '+30 при точном совпадении в названии' },
    { value: 'broad', label: 'Расширенные', hint: 'как основные, для широкого охвата' },
    { value: 'negative', label: 'Минус-слова', hint: '−20 или полное исключение' },
    { value: 'high_priority', label: 'Высокий приоритет', hint: 'дополнительно +10' },
    { value: 'low_priority', label: 'Низкий приоритет', hint: '+2 вместо стандартного балла' },
];

const form = useForm({ value: '', type: 'main' });

const grouped = computed(() =>
    TYPES.map((t) => ({
        ...t,
        items: props.keywords.filter((k) => k.type === t.value),
    })),
);

const submit = () => {
    form.post(route('profiles.keywords.store', props.profileId), {
        preserveScroll: true,
        onSuccess: () => form.reset('value'),
    });
};

const remove = (keyword) => {
    router.delete(route('keywords.destroy', keyword.id), { preserveScroll: true });
};
</script>

<template>
    <section>
        <header class="mb-4">
            <h3 class="text-lg font-medium text-gray-900">Ключевые слова</h3>
            <p class="mt-1 text-sm text-gray-600">
                Для активации направления нужен хотя бы один позитивный ключ
                (основной, точная фраза или расширенный).
            </p>
        </header>

        <form class="flex items-start gap-2" @submit.prevent="submit">
            <div class="flex-1">
                <TextInput v-model="form.value" class="block w-full" placeholder="металлоконструкции" />
                <InputError class="mt-1" :message="form.errors.value" />
            </div>
            <select
                v-model="form.type"
                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <PrimaryButton :disabled="form.processing">Добавить</PrimaryButton>
        </form>
        <InputError class="mt-1" :message="form.errors.type" />

        <div class="mt-6 space-y-4">
            <div v-for="group in grouped" :key="group.value">
                <template v-if="group.items.length">
                    <h4 class="text-sm font-medium text-gray-700">
                        {{ group.label }}
                        <span class="font-normal text-gray-400">— {{ group.hint }}</span>
                    </h4>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="keyword in group.items"
                            :key="keyword.id"
                            :class="group.value === 'negative'
                                ? 'bg-red-50 text-red-800'
                                : 'bg-indigo-50 text-indigo-800'"
                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-sm"
                        >
                            {{ keyword.value }}
                            <button type="button" class="text-gray-400 hover:text-red-600" @click="remove(keyword)">×</button>
                        </span>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>
```

- [ ] **Step 6: Подключить в Edit.vue** — заменить комментарий `<!-- Task 6 -->`:

```vue
<div class="bg-white p-6 shadow-sm sm:rounded-lg">
    <KeywordManager :profile-id="profile.id" :keywords="keywords" />
</div>
```

(+ импорт `import KeywordManager from './Partials/KeywordManager.vue';`)

- [ ] **Step 7: Build + все тесты + Commit** — `git commit -m "A2: ключевые слова (6 типов) — backend и UI"`

---

### Task 7: Активация направления

**Files:**
- Modify: `app/Http/Controllers/SearchProfileController.php`, `routes/web.php`, `resources/js/Pages/Profiles/Edit.vue`
- Create: `resources/js/Pages/Profiles/Partials/ActivationPanel.vue`
- Test: `tests/Feature/Profiles/ActivationTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;

it('нельзя активировать направление без позитивного ключа', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->create(['search_profile_id' => $profile->id, 'type' => KeywordType::Negative, 'value' => 'аренда']);

    $this->actingAs($admin)
        ->post(route('profiles.activate', $profile))
        ->assertSessionHasErrors('activation');

    expect($profile->refresh()->is_active)->toBeFalse();
});

it('активация и деактивация с позитивным ключом', function () {
    $admin = adminWithPlan();
    $profile = SearchProfile::factory()->create(['company_id' => $admin->company_id]);
    Keyword::factory()->create(['search_profile_id' => $profile->id, 'type' => KeywordType::Main, 'value' => 'кран']);

    $this->actingAs($admin)->post(route('profiles.activate', $profile))->assertRedirect();
    expect($profile->refresh()->is_active)->toBeTrue();

    $this->actingAs($admin)->post(route('profiles.deactivate', $profile))->assertRedirect();
    expect($profile->refresh()->is_active)->toBeFalse();
});

it('чужое направление не активировать (404)', function () {
    $admin = adminWithPlan();
    $foreign = SearchProfile::factory()->create();

    $this->actingAs($admin)->post(route('profiles.activate', $foreign))->assertNotFound();
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Методы контроллера** — добавить в `SearchProfileController`:

```php
public function activate(SearchProfile $profile): RedirectResponse
{
    if (! $profile->canActivate()) {
        return back()->withErrors([
            'activation' => 'Добавьте хотя бы один позитивный ключ (основной, точная фраза или расширенный).',
        ]);
    }

    $profile->update(['is_active' => true]);

    return back()->with('success', 'Направление активировано.');
}

public function deactivate(SearchProfile $profile): RedirectResponse
{
    $profile->update(['is_active' => false]);

    return back()->with('success', 'Направление выключено.');
}
```

- [ ] **Step 4: Routes** — в группу:

```php
Route::post('profiles/{profile}/activate', [SearchProfileController::class, 'activate'])->name('profiles.activate');
Route::post('profiles/{profile}/deactivate', [SearchProfileController::class, 'deactivate'])->name('profiles.deactivate');
```

- [ ] **Step 5: ActivationPanel** — `resources/js/Pages/Profiles/Partials/ActivationPanel.vue`:

```vue
<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profile: Object,
    canActivate: Boolean,
});

const error = computed(() => usePage().props.errors?.activation);

const toggle = () => {
    router.post(
        route(props.profile.is_active ? 'profiles.deactivate' : 'profiles.activate', props.profile.id),
        {},
        { preserveScroll: true },
    );
};
</script>

<template>
    <section class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-medium text-gray-900">
                {{ profile.is_active ? 'Направление активно' : 'Направление выключено' }}
            </h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ profile.is_active
                    ? 'Участвует в поиске закупок при запуске радара.'
                    : canActivate
                        ? 'Готово к активации.'
                        : 'Для активации добавьте хотя бы один позитивный ключ.' }}
            </p>
            <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
        </div>
        <PrimaryButton v-if="!profile.is_active" :disabled="!canActivate" @click="toggle">
            Активировать
        </PrimaryButton>
        <SecondaryButton v-else @click="toggle">Выключить</SecondaryButton>
    </section>
</template>
```

Подключить в `Edit.vue` вместо комментария Task 7 (внутри обёртки `bg-white p-6 …`), импорт добавить.

- [ ] **Step 6: Build + тесты + Commit** — `git commit -m "A2: активация направлений с валидацией позитивного ключа"`

---

### Task 8: Источники компании — toggle с лимитом тарифа

**Files:**
- Create: `app/Http/Controllers/CompanySourceController.php`, `resources/js/Pages/Sources/Index.vue`
- Modify: `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.vue` (пункт «Источники»)
- Test: `tests/Feature/Sources/CompanySourceTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Models\CompanySource;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('страница источников показывает реестр с состоянием компании', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    CompanySource::create(['company_id' => $admin->company_id, 'source_id' => Source::where('code', 'eis')->value('id'), 'is_active' => true]);

    $this->actingAs($admin)
        ->get(route('sources.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sources/Index')
            ->has('sources', 2)
            ->where('sources.0.enabled', fn ($v) => is_bool($v)));
});

it('включает и выключает источник', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    $source = Source::where('code', 'eis')->first();

    $this->actingAs($admin)
        ->patch(route('sources.update', $source), ['enabled' => true])
        ->assertRedirect();

    expect(CompanySource::withoutTenancy()->where('company_id', $admin->company_id)->where('source_id', $source->id)->value('is_active'))->toBeTrue();

    $this->actingAs($admin)->patch(route('sources.update', $source), ['enabled' => false])->assertRedirect();
    expect(CompanySource::withoutTenancy()->where('company_id', $admin->company_id)->where('source_id', $source->id)->value('is_active'))->toBeFalse();
});

it('лимит тарифа на число включённых источников', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    $admin->company->activeSubscription()->plan->update(['max_sources' => 1]);

    $eis = Source::where('code', 'eis')->first();
    $manual = Source::where('code', 'manual')->first();

    $this->actingAs($admin)->patch(route('sources.update', $eis), ['enabled' => true])->assertRedirect();
    $this->actingAs($admin)
        ->patch(route('sources.update', $manual), ['enabled' => true])
        ->assertSessionHasErrors('enabled');
});

it('менеджеру запрещено', function () {
    $this->seed(SourceSeeder::class);
    $admin = adminWithPlan();
    $manager = \App\Models\User::factory()->for($admin->company)->create(['role' => \App\Enums\UserRole::Manager]);

    $this->actingAs($manager)->get(route('sources.index'))->assertForbidden();
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: Controller** — `app/Http/Controllers/CompanySourceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CompanySource;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompanySourceController extends Controller
{
    public function index(): Response
    {
        $companySources = CompanySource::query()->get()->keyBy('source_id');

        return Inertia::render('Sources/Index', [
            'sources' => Source::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Source $source) => [
                    'id' => $source->id,
                    'code' => $source->code,
                    'name' => $source->name,
                    'connection_type' => $source->connection_type,
                    'enabled' => (bool) ($companySources[$source->id]->is_active ?? false),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $companyId = $request->user()->company_id;

        if ($validated['enabled']) {
            $plan = $request->user()->company->activeSubscription()?->plan;
            $activeCount = CompanySource::query()->where('is_active', true)->count();
            $alreadyActive = (bool) CompanySource::query()->where('source_id', $source->id)->value('is_active');

            if ($plan === null || (! $alreadyActive && $activeCount >= $plan->max_sources)) {
                throw ValidationException::withMessages([
                    'enabled' => $plan === null
                        ? 'У компании нет активной подписки.'
                        : "Достигнут лимит тарифа: {$plan->max_sources} источников.",
                ]);
            }
        }

        CompanySource::query()->updateOrCreate(
            ['company_id' => $companyId, 'source_id' => $source->id],
            ['is_active' => $validated['enabled']],
        );

        return back()->with('success', $validated['enabled'] ? 'Источник включён.' : 'Источник выключен.');
    }
}
```

- [ ] **Step 4: Routes** — в группу:

```php
Route::get('sources', [CompanySourceController::class, 'index'])->name('sources.index');
Route::patch('sources/{source}', [CompanySourceController::class, 'update'])->name('sources.update');
```

- [ ] **Step 5: Страница** — `resources/js/Pages/Sources/Index.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({ sources: Array });

const TYPE_LABELS = {
    open_data: 'Открытые данные',
    api: 'API',
    manual: 'Ручной импорт',
    parsing: 'Парсинг',
};

const error = computed(() => usePage().props.errors?.enabled);

const toggle = (source) => {
    router.patch(route('sources.update', source.id), { enabled: !source.enabled }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Источники" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Источники закупок</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <p v-if="error" class="mb-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

                <div class="divide-y divide-gray-200 bg-white shadow-sm sm:rounded-lg">
                    <div v-for="source in sources" :key="source.id" class="flex items-center justify-between p-6">
                        <div>
                            <p class="font-medium text-gray-900">{{ source.name }}</p>
                            <p class="text-sm text-gray-500">{{ TYPE_LABELS[source.connection_type] ?? source.connection_type }}</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="source.enabled"
                            :class="source.enabled ? 'bg-indigo-600' : 'bg-gray-200'"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                            @click="toggle(source)"
                        >
                            <span
                                :class="source.enabled ? 'translate-x-6' : 'translate-x-1'"
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                            />
                        </button>
                    </div>
                </div>

                <p class="mt-4 text-sm text-gray-500">
                    Включённые источники участвуют в поиске закупок. Число источников ограничено тарифом.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 6: Раскомментировать пункт «Источники»** в навигации. Build + тесты + Commit — `git commit -m "A2: источники компании (toggle, лимит тарифа)"`

---

### Task 9: Настройки компании

**Files:**
- Create: `app/Http/Controllers/CompanySettingsController.php`, `app/Http/Requests/CompanySettingsRequest.php`, `resources/js/Pages/Company/Edit.vue`
- Modify: `routes/web.php`, `resources/js/Layouts/AuthenticatedLayout.vue` (пункт «Компания»)
- Test: `tests/Feature/Company/CompanySettingsTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use Inertia\Testing\AssertableInertia as Assert;

it('страница настроек отдаёт компанию', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->get(route('company.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Company/Edit')
            ->where('company.id', $admin->company_id));
});

it('админ обновляет профиль компании', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->patch(route('company.update'), [
            'name' => 'ООО «Сталь»',
            'inn' => '7701234567',
            'website' => 'https://stal.example',
            'description' => 'Металлообработка',
            'industries' => ['металлообработка'],
            'products' => ['фермы', 'балки'],
            'services' => ['монтаж'],
            'notification_email' => 'tenders@stal.example',
            'telegram_chat_id' => '-100123456',
        ])
        ->assertRedirect();

    $company = $admin->company->fresh();
    expect($company->name)->toBe('ООО «Сталь»')
        ->and($company->products)->toBe(['фермы', 'балки'])
        ->and($company->notification_email)->toBe('tenders@stal.example');
});

it('валидация: ИНН 10-12 цифр, email корректный', function () {
    $admin = adminWithPlan();

    $this->actingAs($admin)
        ->patch(route('company.update'), [
            'name' => 'X',
            'inn' => '123',
            'notification_email' => 'not-email',
            'industries' => [], 'products' => [], 'services' => [],
        ])
        ->assertSessionHasErrors(['inn', 'notification_email']);
});

it('менеджеру запрещено', function () {
    $admin = adminWithPlan();
    $manager = \App\Models\User::factory()->for($admin->company)->create(['role' => \App\Enums\UserRole::Manager]);

    $this->actingAs($manager)->get(route('company.edit'))->assertForbidden();
    $this->actingAs($manager)->patch(route('company.update'), ['name' => 'X'])->assertForbidden();
});
```

- [ ] **Step 2: Run → FAIL**

- [ ] **Step 3: FormRequest** — `app/Http/Requests/CompanySettingsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-company');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'regex:/^\d{10}(\d{2})?$/'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'industries' => ['array'],
            'industries.*' => ['string', 'max:255'],
            'products' => ['array'],
            'products.*' => ['string', 'max:255'],
            'services' => ['array'],
            'services.*' => ['string', 'max:255'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return ['inn.regex' => 'ИНН — 10 цифр (юрлицо) или 12 цифр (ИП).'];
    }
}
```

- [ ] **Step 4: Controller** — `app/Http/Controllers/CompanySettingsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanySettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
```

- [ ] **Step 5: Routes** — в группу:

```php
Route::get('company', [CompanySettingsController::class, 'edit'])->name('company.edit');
Route::patch('company', [CompanySettingsController::class, 'update'])->name('company.update');
```

- [ ] **Step 6: Страница** — `resources/js/Pages/Company/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TagInput from '@/Components/TagInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ company: Object });

const form = useForm({
    name: props.company.name ?? '',
    inn: props.company.inn ?? '',
    website: props.company.website ?? '',
    description: props.company.description ?? '',
    industries: props.company.industries ?? [],
    products: props.company.products ?? [],
    services: props.company.services ?? [],
    notification_email: props.company.notification_email ?? '',
    telegram_chat_id: props.company.telegram_chat_id ?? '',
});
</script>

<template>
    <Head title="Компания" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Настройки компании</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form
                    class="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg"
                    @submit.prevent="form.patch(route('company.update'), { preserveScroll: true })"
                >
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Название" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                        <div>
                            <InputLabel for="inn" value="ИНН" />
                            <TextInput id="inn" v-model="form.inn" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.inn" />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="website" value="Сайт" />
                        <TextInput id="website" v-model="form.website" type="url" class="mt-1 block w-full" placeholder="https://" />
                        <InputError class="mt-2" :message="form.errors.website" />
                    </div>

                    <div>
                        <InputLabel for="description" value="Описание деятельности" />
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <InputError class="mt-2" :message="form.errors.description" />
                    </div>

                    <div>
                        <InputLabel value="Отрасли" />
                        <TagInput v-model="form.industries" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Основные товары" />
                        <TagInput v-model="form.products" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Основные услуги" />
                        <TagInput v-model="form.services" class="mt-1" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <InputLabel for="notification_email" value="Email для уведомлений" />
                            <TextInput id="notification_email" v-model="form.notification_email" type="email" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.notification_email" />
                        </div>
                        <div>
                            <InputLabel for="telegram_chat_id" value="Telegram chat_id" />
                            <TextInput id="telegram_chat_id" v-model="form.telegram_chat_id" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.telegram_chat_id" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing">Сохранить</PrimaryButton>
                        <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0">
                            <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Сохранено.</p>
                        </Transition>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 7: Раскомментировать пункт «Компания»**. Build + тесты + Commit — `git commit -m "A2: настройки компании"`

---

### Task 10: Финальная проверка A2

- [ ] **Step 1: Полный прогон** — `php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && npm run build`

- [ ] **Step 2: Ручной smoke** — `php artisan serve` → войти `admin@tender.local`/`password` → создать направление «Металлоконструкции» с ключами → активировать → включить источники → сохранить настройки компании. Затем войти `manager@tender.local` → убедиться, что пунктов меню Направления/Источники/Компания нет, а прямой URL даёт 403.

- [ ] **Step 3: Роадмап** — отметить A2 «выполнен» в `docs/superpowers/plans/2026-06-05-stage-a-roadmap.md`.

- [ ] **Step 4: Финальное код-ревью ветки** (контроллер процесса диспатчит ревьюера на весь диапазон A2), затем merge в main по навыку finishing-a-development-branch.

---

## Definition of Done (A2)

- Через UI: направление создаётся/редактируется/удаляется; ключи 6 типов добавляются/удаляются с подсказками по баллам; активация требует позитивный ключ; источники включаются с лимитом тарифа; настройки компании сохраняются.
- Менеджер/наблюдатель не видят пунктов управления и получают 403 на прямые URL; чужие сущности — 404.
- Лимиты тарифа (направления, источники) энфорсятся с понятными русскими сообщениями.
- Интерфейс и валидация на русском; все тесты, pint, phpstan, build — зелёные.
