<?php

use App\Console\Commands\CollectSourceCommand;
use App\Console\Commands\EisTokenCheckCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CollectSourceCommand::class, ['eis'])
    ->cron(config('eis.schedule_cron', '0 */4 * * *'))
    ->withoutOverlapping();

// Ежедневная проверка срока годности токена ЕИС — заранее предупредить о протухании.
Schedule::command(EisTokenCheckCommand::class)
    ->dailyAt('08:00')
    ->withoutOverlapping();
