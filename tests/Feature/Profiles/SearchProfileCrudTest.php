<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\SearchProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

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
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);

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
    $admin = User::factory()->for($company)->create(['role' => UserRole::Admin]);

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

it('flash success доступен Inertia после создания', function () {
    $admin = adminWithPlan();

    $response = $this->actingAs($admin)
        ->post(route('profiles.store'), [
            'name' => 'Флеш-тест', 'law_types' => [], 'regions' => [], 'excluded_regions' => [], 'okpd2_codes' => [],
            'deadline_min_days' => 0, 'notification_threshold' => 60, 'exclude_on_negative_in_title' => false,
        ]);

    // контроллер реально кладёт flash в сессию…
    $response->assertSessionHas('success', 'Направление создано. Добавьте ключевые слова и активируйте его.');

    $profile = SearchProfile::withoutTenancy()->where('name', 'Флеш-тест')->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['success' => 'Направление создано. Добавьте ключевые слова и активируйте его.'])
        ->get(route('profiles.edit', $profile))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.success', 'Направление создано. Добавьте ключевые слова и активируйте его.'));
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
