<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationChannel;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Фабрика: NotificationChannel → ChannelNotifier. Резолвит реализацию из
 * контейнера (так MaxGateway инжектится в MaxChannelNotifier). NotifyJob
 * получает резолвер через DI и просит нотифаер для своего канала.
 */
final class ChannelNotifierResolver
{
    public function __construct(private readonly Container $container) {}

    public function for(NotificationChannel $channel): ChannelNotifier
    {
        return match ($channel) {
            NotificationChannel::Max => $this->container->make(MaxChannelNotifier::class),
            NotificationChannel::Email => $this->container->make(EmailChannelNotifier::class),
            default => throw new InvalidArgumentException(
                "Канал {$channel->value} не поддерживает уведомления о матчах."
            ),
        };
    }
}
