<?php

namespace App\Notifications\Channels;

use App\Models\Company;
use App\Models\TenderMatch;

/**
 * Абстракция канала уведомлений (MAX/Email/...). Скрывает за собой
 * резолв адресата (recipientFor) и саму доставку (send). NotifyJob работает
 * только с этим интерфейсом; конкретный нотифаер выбирается по
 * NotificationChannel через ChannelNotifierResolver.
 */
interface ChannelNotifier
{
    /**
     * Адресат уведомления для компании в рамках канала
     * (max_chat_id / notification_email / ...). null или '' → канал не настроен.
     */
    public function recipientFor(Company $company): ?string;

    /**
     * Рендерит и отправляет уведомление о матче. Бросает Throwable при
     * ошибке доставки (→ NotifyJob залогирует Failed и пробросит для ретрая).
     */
    public function send(string $recipient, TenderMatch $match): void;
}
