<?php

use App\Enums\ActionRecommendation;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\TenderSourceStatus;
use App\Jobs\NotifyJob;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Notifications\FakeMaxGateway;
use App\Notifications\MaxGateway;
use App\Services\Notification\NotificationDecider;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
    $this->fakeGateway = new FakeMaxGateway;
    app()->instance(MaxGateway::class, $this->fakeGateway);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Создаёт компанию (+chat_id), профиль, закупку, radar_run и матч; возвращает матч.
 */
function notifyFixture(?string $chatId = '111222', int $score = 75): TenderMatch
{
    $company = Company::factory()->create(['max_chat_id' => $chatId]);
    $profile = SearchProfile::factory()->for($company)->create(['notification_threshold' => 60]);
    $tender = Tender::factory()->create([
        'title' => 'Поставка кранов',
        'price' => '1500000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_status' => TenderSourceStatus::Active,
        'owner_company_id' => null,
    ]);
    $run = RadarRun::create([
        'company_id' => $company->id,
        'search_profile_id' => $profile->id,
        'trigger' => RunTrigger::Import,
        'status' => RunStatus::Ok,
        'started_at' => now(),
        'notified_count' => 0,
    ]);

    return TenderMatch::factory()->create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'radar_run_id' => $run->id,
        'score' => $score,
        'level' => RelevanceLevel::fromScore($score),
        'action_recommendation' => ActionRecommendation::ReviewDocs,
        'reason' => [['rule' => 'keyword_in_title', 'points' => 20, 'detail' => 'кран']],
        'risk_flags' => [],
    ]);
}

it('happy-path: отправляет, пишет sent-лог и инкрементит notified_count', function () {
    $match = notifyFixture();

    (new NotifyJob($match->id))->handle($this->fakeGateway, app(NotificationDecider::class));

    expect($this->fakeGateway->sent)->toHaveCount(1)
        ->and($this->fakeGateway->sent[0]['chatId'])->toBe('111222')
        ->and($this->fakeGateway->sent[0]['text'])->toContain('Поставка кранов');

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->channel)->toBe(NotificationChannel::Max)
        ->and($log->recipient)->toBe('111222')
        ->and($log->company_id)->toBe($match->company_id)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->payload['price'])->toBe('1500000.00')
        ->and($log->payload['source_status'])->toBe('active')
        ->and($log->payload['score'])->toBe(75);

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(1);
});

it('нет max_chat_id → failed-лог, без отправки, notified_count не растёт', function () {
    $match = notifyFixture(chatId: null);

    (new NotifyJob($match->id))->handle($this->fakeGateway, app(NotificationDecider::class));

    expect($this->fakeGateway->sent)->toBeEmpty();

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('chat_id');

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(0);
});

it('транспорт-ошибка → failed-лог и проброс исключения (ретрай)', function () {
    $match = notifyFixture();
    $this->fakeGateway->throwOnSend = new RuntimeException('max 500');

    $job = new NotifyJob($match->id);

    expect(fn () => $job->handle($this->fakeGateway, app(NotificationDecider::class)))
        ->toThrow(RuntimeException::class, 'max 500');

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->error)->toContain('max 500');

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(0);
});

it('антидубль: повтор без изменений → выход без отправки и без лога', function () {
    $match = notifyFixture();

    // первый раз — отправит и залогирует sent
    (new NotifyJob($match->id))->handle($this->fakeGateway, app(NotificationDecider::class));
    expect(NotificationLog::withoutTenancy()->count())->toBe(1);

    // второй раз без изменений — Decider скажет «не слать»
    $fresh = new FakeMaxGateway;
    (new NotifyJob($match->id))->handle($fresh, app(NotificationDecider::class));

    expect($fresh->sent)->toBeEmpty()
        ->and(NotificationLog::withoutTenancy()->count())->toBe(1); // новых логов нет
});

it('tenant-изоляция: джоб матча компании А шлёт на chat_id А, не Б', function () {
    $matchA = notifyFixture(chatId: 'AAA');
    $matchB = notifyFixture(chatId: 'BBB');

    (new NotifyJob($matchA->id))->handle($this->fakeGateway, app(NotificationDecider::class));

    expect($this->fakeGateway->sent)->toHaveCount(1)
        ->and($this->fakeGateway->sent[0]['chatId'])->toBe('AAA');

    // Лог записан под компанию А, не Б
    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->company_id)->toBe($matchA->company_id)
        ->and($log->company_id)->not->toBe($matchB->company_id);
});
