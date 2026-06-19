<?php

use App\Enums\ActionRecommendation;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\RelevanceLevel;
use App\Enums\TenderSourceStatus;
use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Services\Notification\NotificationDecider;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-18 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Хелпер: создаёт компанию, профиль (threshold=60), закупку и матч (со score),
 * возвращает свежий матч с загруженными tender+searchProfile.
 */
function deciderFixture(array $tenderAttrs = [], int $score = 75, int $threshold = 60): TenderMatch
{
    $company = Company::factory()->create();
    $profile = SearchProfile::factory()->for($company)->create([
        'notification_threshold' => $threshold,
    ]);
    $tender = Tender::factory()->create(array_merge([
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00'),
        'source_status' => TenderSourceStatus::Active,
        'owner_company_id' => null,
    ], $tenderAttrs));

    $match = TenderMatch::factory()->create([
        'company_id' => $company->id,
        'tender_id' => $tender->id,
        'search_profile_id' => $profile->id,
        'score' => $score,
        'level' => RelevanceLevel::fromScore($score),
        'action_recommendation' => ActionRecommendation::ReviewDocs,
    ]);

    return TenderMatch::withoutTenancy()
        ->with(['tender', 'searchProfile'])
        ->findOrFail($match->id);
}

/** Хелпер: пишет прошлый sent-лог со снимком payload. */
function pastSentLog(TenderMatch $match, array $payload): NotificationLog
{
    return NotificationLog::create([
        'company_id' => $match->company_id,
        'tender_id' => $match->tender_id,
        'search_profile_id' => $match->search_profile_id,
        'channel' => NotificationChannel::Max,
        'recipient' => '123456',
        'status' => NotificationStatus::Sent,
        'payload' => $payload,
        'sent_at' => now(),
    ]);
}

function decider(): NotificationDecider
{
    return app(NotificationDecider::class);
}

it('первый раз (нет прошлого лога) → слать', function () {
    $match = deciderFixture();
    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('повтор без изменений → НЕ слать', function () {
    $match = deciderFixture();
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});

it('ре-триггер: цена выросла ≥10% → слать', function () {
    $match = deciderFixture(['price' => '1200000.00']); // +20%
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('ре-триггер: цена изменилась <10% → НЕ слать', function () {
    $match = deciderFixture(['price' => '1050000.00']); // +5%
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});

it('ре-триггер: изменился дедлайн → слать', function () {
    $match = deciderFixture(['deadline_at' => Carbon::parse('2026-07-10 18:00:00')]);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('ре-триггер: source_status стал терминальным (cancelled) → слать', function () {
    $match = deciderFixture(['source_status' => TenderSourceStatus::Cancelled]);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('source_status уже был терминальным (без перехода) → НЕ слать', function () {
    $match = deciderFixture(['source_status' => TenderSourceStatus::Completed]);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'completed',
        'score' => 75,
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});

it('ре-триггер: score пересёк порог снизу вверх → слать', function () {
    $match = deciderFixture(score: 65, threshold: 60); // текущий >= порог
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 55, // прошлый < порог
    ]);

    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('игнорирует sent-лог с тем же профилем+закупкой, но ЧУЖИМ company_id → слать (как при отсутствии лога)', function () {
    $match = deciderFixture();
    $otherCompany = Company::factory()->create();

    // Подложный/повреждённый лог: тот же search_profile_id + tender_id, но другая компания.
    NotificationLog::create([
        'company_id' => $otherCompany->id, // чужая компания
        'tender_id' => $match->tender_id,
        'search_profile_id' => $match->search_profile_id,
        'channel' => NotificationChannel::Max,
        'recipient' => '999999',
        'status' => NotificationStatus::Sent,
        'payload' => [
            'price' => '1000000.00',
            'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
            'source_status' => 'active',
            'score' => 75,
        ],
        'sent_at' => now(),
    ]);

    // Для нашей компании прошлого лога нет → решатель должен вернуть true.
    expect(decider()->shouldNotify($match))->toBeTrue();
});

it('score был выше порога и остался → НЕ слать (нет пересечения снизу)', function () {
    $match = deciderFixture(score: 80, threshold: 60);
    pastSentLog($match, [
        'price' => '1000000.00',
        'deadline_at' => Carbon::parse('2026-07-01 18:00:00')->toIso8601String(),
        'source_status' => 'active',
        'score' => 70, // уже был >= порога
    ]);

    expect(decider()->shouldNotify($match))->toBeFalse();
});
