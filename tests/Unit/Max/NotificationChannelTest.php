<?php

use App\Enums\NotificationChannel;

it('NotificationChannel содержит кейс Max со значением max', function () {
    expect(NotificationChannel::Max->value)->toBe('max');
});

it('NotificationChannel::from корректно парсит строку max', function () {
    expect(NotificationChannel::from('max'))->toBe(NotificationChannel::Max);
});
