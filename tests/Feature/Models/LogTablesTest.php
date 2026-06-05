<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\AiUsage;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\Tender;
use Illuminate\Database\QueryException;

it('логирует уведомление с каналом web', function () {
    $log = NotificationLog::create([
        'company_id' => Company::factory()->create()->id,
        'tender_id' => Tender::factory()->create()->id,
        'channel' => NotificationChannel::Web,
        'recipient' => 'user:1',
        'status' => NotificationStatus::Queued,
        'payload' => ['score' => 80],
    ]);
    expect($log->channel)->toBe(NotificationChannel::Web);
});

it('ai_usage уникален по компании и периоду', function () {
    $company = Company::factory()->create();
    AiUsage::create(['company_id' => $company->id, 'period' => '2026-06', 'calls' => 1, 'tokens_used' => 100]);
    AiUsage::create(['company_id' => $company->id, 'period' => '2026-06', 'calls' => 2, 'tokens_used' => 200]);
})->throws(QueryException::class);
