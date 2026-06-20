<?php

use App\Enums\ActionRecommendation;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\RelevanceLevel;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\TenderSourceStatus;
use App\Jobs\NotifyJob;
use App\Mail\TenderNotificationMail;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\RadarRun;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Notifications\Channels\ChannelNotifierResolver;
use App\Services\Notification\NotificationDecider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
    Mail::fake();
});

afterEach(fn () => Carbon::setTestNow());

/** Создаёт компанию (+notification_email), профиль, закупку, radar_run и матч. */
function emailFixture(?string $email = 'ops@example.test', int $score = 75): TenderMatch
{
    $company = Company::factory()->create(['max_chat_id' => null, 'notification_email' => $email]);
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

function emailJob(TenderMatch $match): void
{
    (new NotifyJob($match->id, NotificationChannel::Email))
        ->handle(app(ChannelNotifierResolver::class), app(NotificationDecider::class));
}

it('email happy-path: шлёт Mailable, пишет sent-лог (channel=email) и инкрементит notified_count', function () {
    $match = emailFixture();

    emailJob($match);

    Mail::assertSent(TenderNotificationMail::class, function (TenderNotificationMail $mail) use ($match) {
        return $mail->hasTo('ops@example.test') && $mail->match->id === $match->id;
    });

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->channel)->toBe(NotificationChannel::Email)
        ->and($log->recipient)->toBe('ops@example.test')
        ->and($log->company_id)->toBe($match->company_id)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->payload['price'])->toBe('1500000.00')
        ->and($log->payload['score'])->toBe(75);

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(1);
});

it('email без notification_email → failed-лог (no recipient), без письма, notified_count не растёт', function () {
    $match = emailFixture(email: null);

    emailJob($match);

    Mail::assertNothingSent();

    $log = NotificationLog::withoutTenancy()->sole();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and($log->channel)->toBe(NotificationChannel::Email)
        ->and($log->error)->toContain('no recipient');

    expect(RadarRun::withoutTenancy()->findOrFail($match->radar_run_id)->notified_count)->toBe(0);
});

it('email антидубль: повтор без изменений → выход без письма и без лога', function () {
    $match = emailFixture();

    emailJob($match);
    expect(NotificationLog::withoutTenancy()->count())->toBe(1);
    Mail::assertSent(TenderNotificationMail::class, 1);

    // второй прогон без изменений матча — Decider скажет «не слать» по каналу Email
    emailJob($match);

    Mail::assertSent(TenderNotificationMail::class, 1); // новых писем нет
    expect(NotificationLog::withoutTenancy()->count())->toBe(1); // новых логов нет
});

it('email tenant-изоляция: письмо уходит на адрес своей компании', function () {
    $matchA = emailFixture(email: 'a@example.test');
    $matchB = emailFixture(email: 'b@example.test');

    emailJob($matchA);
    emailJob($matchB);

    Mail::assertSent(TenderNotificationMail::class, 2);
    Mail::assertSent(TenderNotificationMail::class, fn (TenderNotificationMail $m) => $m->hasTo('a@example.test') && $m->match->company_id === $matchA->company_id);
    Mail::assertSent(TenderNotificationMail::class, fn (TenderNotificationMail $m) => $m->hasTo('b@example.test') && $m->match->company_id === $matchB->company_id);

    $logA = NotificationLog::withoutTenancy()->where('recipient', 'a@example.test')->sole();
    $logB = NotificationLog::withoutTenancy()->where('recipient', 'b@example.test')->sole();
    expect($logA->company_id)->toBe($matchA->company_id)
        ->and($logB->company_id)->toBe($matchB->company_id)
        ->and($logA->company_id)->not->toBe($logB->company_id);
});
