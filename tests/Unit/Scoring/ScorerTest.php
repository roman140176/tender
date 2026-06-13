<?php

use App\Enums\ActionRecommendation;
use App\Enums\KeywordType;
use App\Enums\LawType;
use App\Enums\RelevanceLevel;
use App\Enums\TenderSourceStatus;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Scoring\KeywordHit;
use App\Scoring\Scorer;
use App\Scoring\ScoringWeights;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Фиксируем «сейчас» для детерминизма дедлайн-правил.
    Carbon::setTestNow('2026-06-13 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Нейтральная по неключевым правилам пара: цена/мин не сравниваются (min null),
 * регион не приоритетный, дедлайн в «нейтральной» зоне (3 дня), статус active, окпд пуст.
 * Остаётся только вклад переданных попаданий ключей.
 */
function neutralTender(array $overrides = []): Tender
{
    return Tender::factory()->make(array_merge([
        'source_id' => 1,   // явный FK: иначе make() резолвит Source::factory() через create() (INSERT)
        'title' => 'Поставка кранов мостовых',
        'description' => 'Комплектная поставка оборудования',
        'price' => null,
        'region' => 'Тверь',
        'law_type' => LawType::Fz44,
        'source_status' => TenderSourceStatus::Active,
        'deadline_at' => Carbon::parse('2026-06-16 12:00:00'), // +3 дня (нейтрально)
        'okpd2' => [],
        'ktru' => [],
        'owner_company_id' => null,
    ], $overrides));
}

function neutralProfile(array $overrides = []): SearchProfile
{
    return SearchProfile::factory()->make(array_merge([
        'company_id' => 1,  // явный FK: иначе make() резолвит Company::factory() через create() (INSERT)
        'min_price' => null,
        'max_price' => null,
        'regions' => [],
        'excluded_regions' => [],
        'okpd2_codes' => [],
        'law_types' => [],
        'exclude_on_negative_in_title' => false,
        'scoring_weights' => null,
    ], $overrides));
}

function score(Tender $tender, SearchProfile $profile, array $positive, array $negative = [])
{
    return app(Scorer::class)->score($tender, $profile, ScoringWeights::for($profile), $positive, $negative);
}

it('exact в названии → +30, правила 2-3 для неё не дублируют базу', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны мостовые', KeywordType::Exact, 'title'),
    ]);

    expect($r->score)->toBe(30)
        ->and($r->level)->toBe(RelevanceLevel::Low)
        ->and($r->recommendation)->toBe(ActionRecommendation::Decline)
        ->and($r->matchedKeywords)->toBe([
            ['value' => 'краны мостовые', 'type' => 'exact', 'field' => 'title'],
        ]);
});

it('exact только в описании → +15', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны мостовые', KeywordType::Exact, 'description'),
    ]);

    expect($r->score)->toBe(15);
});

it('main в названии → +20; в описании → +15 (максимум по полю)', function () {
    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ])->score)->toBe(20);

    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Broad, 'description'),
    ])->score)->toBe(15);
});

it('exact в названии + main в описании = 30 + 15 = 45', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны мостовые', KeywordType::Exact, 'title'),
        new KeywordHit('лебёдки', KeywordType::Main, 'description'),
    ]);

    expect($r->score)->toBe(45);
});

it('high_priority даёт +10 поверх базового правила', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),       // +20
        new KeywordHit('срочно', KeywordType::HighPriority, 'description'), // +10
    ]);

    expect($r->score)->toBe(30);
});

it('high_priority сам по себе не даёт +20/+15, только +10', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('срочно', KeywordType::HighPriority, 'title'),
    ]);

    expect($r->score)->toBe(10);
});

it('low_priority +2 только при отсутствии main/exact/broad', function () {
    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('доставка', KeywordType::LowPriority, 'title'),
    ])->score)->toBe(2);

    // есть main → low_priority не начисляется
    expect(score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
        new KeywordHit('доставка', KeywordType::LowPriority, 'title'),
    ])->score)->toBe(20);
});

it('негатив в любом поле → −20 однократно', function () {
    $r = score(neutralTender(), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ], [
        new KeywordHit('бывшие в употреблении', KeywordType::Negative, 'description'),
    ]);

    expect($r->score)->toBe(0) // 20 - 20
        ->and($r->matchedNegativeKeywords)->toBe([
            ['value' => 'бывшие в употреблении', 'type' => 'negative', 'field' => 'description'],
        ]);
});

it('негатив в названии при exclude_on_negative_in_title → excluded, балл не считается', function () {
    $r = score(neutralTender(), neutralProfile(['exclude_on_negative_in_title' => true]), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ], [
        new KeywordHit('бу', KeywordType::Negative, 'title'),
    ]);

    expect($r->excluded)->toBeTrue();
});

it('негатив в описании при exclude_on_negative_in_title НЕ исключает (только −20)', function () {
    $r = score(neutralTender(), neutralProfile(['exclude_on_negative_in_title' => true]), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ], [
        new KeywordHit('бу', KeywordType::Negative, 'description'),
    ]);

    expect($r->excluded)->toBeFalse()->and($r->score)->toBe(0);
});

it('цена ≥ min → +20; цена < min → −10; флаг no_price при NULL', function () {
    $profile = neutralProfile(['min_price' => '1000000']);

    $above = score(neutralTender(['price' => '1500000']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($above->score)->toBe(40); // 20 + 20

    $below = score(neutralTender(['price' => '500000']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($below->score)->toBe(10); // 20 - 10

    $noPrice = score(neutralTender(['price' => null]), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($noPrice->score)->toBe(20)               // нейтрально
        ->and($noPrice->riskFlags)->toContain('no_price');
});

it('цена сравнивается через bccomp (без float-погрешности на копейках)', function () {
    $profile = neutralProfile(['min_price' => '1000000.10']);

    expect(score(neutralTender(['price' => '1000000.10']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ])->score)->toBe(40); // равно min → ≥ → +20

    expect(score(neutralTender(['price' => '1000000.09']), $profile, [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ])->score)->toBe(10); // на копейку меньше → −10
});

it('приоритетный регион → +10', function () {
    $r = score(neutralTender(['region' => 'Москва']), neutralProfile(['regions' => ['Москва']]), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);

    expect($r->score)->toBe(30);
});

it('дедлайн > far_days → +10; < soon_days → −20 + флаг; NULL → флаг no_deadline', function () {
    $far = score(neutralTender(['deadline_at' => Carbon::parse('2026-06-25 12:00:00')]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($far->score)->toBe(30); // +10

    $soon = score(neutralTender(['deadline_at' => Carbon::parse('2026-06-14 06:00:00')]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($soon->score)->toBe(0)                       // 20 - 20
        ->and($soon->riskFlags)->toContain('deadline_soon');

    $none = score(neutralTender(['deadline_at' => null]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($none->score)->toBe(20)
        ->and($none->riskFlags)->toContain('no_deadline');
});

it('окпд2 префиксное совпадение → +5; пустой список профиля — неактивно', function () {
    $withCode = score(
        neutralTender(['okpd2' => ['28.22.14.000']]),
        neutralProfile(['okpd2_codes' => ['28.22']]),
        [new KeywordHit('краны', KeywordType::Main, 'title')],
    );
    expect($withCode->score)->toBe(25); // +5

    $noProfileCodes = score(
        neutralTender(['okpd2' => ['28.22.14.000']]),
        neutralProfile(['okpd2_codes' => []]),
        [new KeywordHit('краны', KeywordType::Main, 'title')],
    );
    expect($noProfileCodes->score)->toBe(20);
});

it('source_status completed/cancelled → −30 + флаг source_closed; unknown как active', function () {
    $closed = score(neutralTender(['source_status' => TenderSourceStatus::Completed]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($closed->score)->toBe(0)                     // clamp: 20 - 30 = -10 → 0
        ->and($closed->riskFlags)->toContain('source_closed');

    $unknown = score(neutralTender(['source_status' => TenderSourceStatus::Unknown]), neutralProfile(), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);
    expect($unknown->score)->toBe(20);
});

it('итог обрезается в [0, 100]', function () {
    // exact_title 30 + main_title 20 уже невозможны вместе (exact гасит правило 2),
    // поэтому набираем потолок через override весов.
    $profile = neutralProfile([
        'min_price' => '1000000',
        'regions' => ['Москва'],
        'okpd2_codes' => ['28'],
        'scoring_weights' => ['exact_in_title' => 95],
    ]);
    $tender = neutralTender([
        'price' => '2000000',
        'region' => 'Москва',
        'okpd2' => ['28.22'],
        'deadline_at' => Carbon::parse('2026-07-01 12:00:00'),
    ]);

    $r = score($tender, $profile, [new KeywordHit('кран', KeywordType::Exact, 'title')]);
    // 95 + 20(price) + 10(region) + 5(okpd) + 10(deadline) = 140 → clamp 100
    expect($r->score)->toBe(100)->and($r->level)->toBe(RelevanceLevel::High)
        ->and($r->recommendation)->toBe(ActionRecommendation::HandOver);
});

it('границы уровней «≥»: 80→high, 60→medium, 40→weak, 39→low', function () {
    foreach ([[80, RelevanceLevel::High], [60, RelevanceLevel::Medium], [40, RelevanceLevel::Weak], [39, RelevanceLevel::Low]] as [$target, $level]) {
        $profile = neutralProfile(['scoring_weights' => ['keyword_in_title' => $target]]);
        $r = score(neutralTender(), $profile, [new KeywordHit('краны', KeywordType::Main, 'title')]);
        expect($r->score)->toBe($target)->and($r->level)->toBe($level);
    }
});

it('reason перечисляет сработавшие правила с баллами', function () {
    $r = score(neutralTender(['price' => '1500000']), neutralProfile(['min_price' => '1000000']), [
        new KeywordHit('краны', KeywordType::Main, 'title'),
    ]);

    $rules = array_column($r->reason, 'points', 'rule');
    expect($rules)->toHaveKey('keyword_in_title')
        ->and($rules['keyword_in_title'])->toBe(20)
        ->and($rules)->toHaveKey('price_above_min')
        ->and($rules['price_above_min'])->toBe(20);
});
