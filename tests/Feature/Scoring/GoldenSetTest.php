<?php

use App\Enums\KeywordType;
use App\Enums\LawType;
use App\Enums\RunTrigger;
use App\Enums\TenderSourceStatus;
use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Keyword;
use App\Models\SearchProfile;
use App\Models\Source;
use App\Models\Tender;
use App\Models\TenderMatch;
use App\Scoring\MatchProfileService;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-06-13 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('воспроизводит эталонные баллы golden-набора', function () {
    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/golden/profile_metal.json')), true);

    $company = Company::factory()->create();
    $source = Source::factory()->create();
    Source::factory()->create(['code' => 'manual']);
    CompanySource::withoutTenancy()->create(['company_id' => $company->id, 'source_id' => $source->id, 'is_active' => true]);

    $p = $data['profile'];
    $profile = SearchProfile::factory()->for($company)->create([
        'is_active' => true,
        'name' => $p['name'],
        'min_price' => $p['min_price'],
        'max_price' => $p['max_price'],
        'regions' => $p['regions'],
        'excluded_regions' => $p['excluded_regions'],
        'okpd2_codes' => $p['okpd2_codes'],
        'law_types' => $p['law_types'],
        'exclude_on_negative_in_title' => $p['exclude_on_negative_in_title'],
    ]);
    foreach ($p['keywords'] as $kw) {
        Keyword::factory()->for($profile)->create(['value' => $kw['value'], 'type' => KeywordType::from($kw['type'])]);
    }

    $tenders = [];
    foreach ($data['cases'] as $i => $case) {
        $tenders[$i] = Tender::factory()->create([
            'source_id' => $source->id,
            'title' => $case['title'],
            'description' => $case['description'],
            'price' => $case['price'],
            'region' => $case['region'],
            'law_type' => LawType::from($case['law_type']),
            'okpd2' => $case['okpd2'],
            'source_status' => TenderSourceStatus::from($case['source_status']),
            'deadline_at' => now()->addDays($case['deadline_offset_days']),
            'owner_company_id' => null,
        ]);
    }

    app(MatchProfileService::class)->run($profile->refresh(), RunTrigger::Manual);

    $failures = [];
    foreach ($data['cases'] as $i => $case) {
        $match = TenderMatch::withoutTenancy()->where('tender_id', $tenders[$i]->id)->first();
        $expectMatch = $case['expected_match'] ?? true;

        if (! $expectMatch) {
            if ($match !== null) {
                $failures[] = "Кейс #{$i} ({$case['note']}): ожидался НЕ-матч, получен score={$match->score}";
            }

            continue;
        }

        if ($match === null) {
            $failures[] = "Кейс #{$i} ({$case['note']}): ожидался матч score={$case['expected_score']}, матча нет";

            continue;
        }
        if ($match->score !== $case['expected_score']) {
            $failures[] = "Кейс #{$i} ({$case['note']}): ожидался score={$case['expected_score']}, получен {$match->score}";
        }
    }

    expect($failures)->toBe([]);
});
