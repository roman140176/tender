<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Telegram = 'telegram';
    case Email = 'email';
    case Web = 'web';
    case Webhook = 'webhook';
}
