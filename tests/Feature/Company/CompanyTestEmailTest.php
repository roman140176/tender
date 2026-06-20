<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Mail\TestNotificationMail;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('отправляет тестовое письмо и пишет лог sent (channel=email)', function () {
    $admin = adminWithPlan();
    $admin->company->update(['notification_email' => 'ops@example.test']);

    $this->actingAs($admin)
        ->post(route('company.test-email'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(TestNotificationMail::class, fn (TestNotificationMail $m) => $m->hasTo('ops@example.test'));

    $log = NotificationLog::withoutTenancy()->where('company_id', $admin->company_id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->channel)->toBe(NotificationChannel::Email)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->recipient)->toBe('ops@example.test');
});

it('пустой notification_email — flash-ошибка, без письма и без лога', function () {
    $admin = adminWithPlan();
    $admin->company->update(['notification_email' => null]);

    $this->actingAs($admin)
        ->post(route('company.test-email'))
        ->assertRedirect()
        ->assertSessionHas('error');

    Mail::assertNothingSent();
    expect(NotificationLog::withoutTenancy()->where('company_id', $admin->company_id)->count())->toBe(0);
});

it('менеджеру запрещено отправлять тестовое письмо — 403', function () {
    $admin = adminWithPlan();
    $admin->company->update(['notification_email' => 'ops@example.test']);
    $manager = User::factory()->for($admin->company)->create(['role' => UserRole::Manager]);

    $this->actingAs($manager)->post(route('company.test-email'))->assertForbidden();

    Mail::assertNothingSent();
});

it('гость — редирект на login', function () {
    $this->post(route('company.test-email'))->assertRedirect(route('login'));
    Mail::assertNothingSent();
});
