<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Requests\CompanySettingsRequest;
use App\Mail\TestNotificationMail;
use App\Models\NotificationLog;
use App\Notifications\MaxGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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

    public function sendTestEmail(Request $request): RedirectResponse
    {
        Gate::authorize('manage-company');

        $company = $request->user()->company;
        $email = (string) ($company->notification_email ?? '');

        if ($email === '') {
            return back()->with('error', 'Укажите email для уведомлений перед отправкой тестового письма.');
        }

        try {
            Mail::to($email)->send(new TestNotificationMail);
        } catch (\Throwable $e) {
            NotificationLog::create([
                'company_id' => $company->id,
                'channel' => NotificationChannel::Email,
                'recipient' => $email,
                'status' => NotificationStatus::Failed,
                'payload' => ['kind' => 'test'],
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Не удалось отправить тестовое письмо. Проверьте email и настройки почты.');
        }

        NotificationLog::create([
            'company_id' => $company->id,
            'channel' => NotificationChannel::Email,
            'recipient' => $email,
            'status' => NotificationStatus::Sent,
            'payload' => ['kind' => 'test'],
            'sent_at' => now(),
        ]);

        return back()->with('success', 'Тестовое письмо отправлено на '.$email.'.');
    }
}
