<?php

use App\Enums\UserRole;
use App\Models\User;
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
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);

    $this->actingAs($manager)->get(route('company.edit'))->assertForbidden();
    $this->actingAs($manager)->patch(route('company.update'), ['name' => 'X'])->assertForbidden();
});
