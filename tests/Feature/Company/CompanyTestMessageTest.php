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
            throw new RuntimeException('max down');
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
