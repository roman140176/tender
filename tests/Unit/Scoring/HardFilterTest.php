<?php

use App\Enums\LawType;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Scoring\HardFilter;

function filterTender(array $overrides = []): Tender
{
    return Tender::factory()->make(array_merge([
        'source_id' => 1,
        'region' => 'Тверь',
        'price' => '1500000',
        'law_type' => LawType::Fz44,
        'owner_company_id' => null,
    ], $overrides));
}

function filterProfile(array $overrides = []): SearchProfile
{
    $profile = SearchProfile::factory()->make(array_merge([
        'company_id' => 7,
        'law_types' => [],
        'regions' => [],
        'excluded_regions' => [],
        'min_price' => null,
        'max_price' => null,
    ], $overrides));

    return $profile;
}

function passes(Tender $tender, SearchProfile $profile, array $enabledSourceIds = [1]): bool
{
    return app(HardFilter::class)->passes($tender, $profile, $enabledSourceIds);
}

it('пустой law_types пропускает любой закон, включая other', function () {
    expect(passes(filterTender(['law_type' => LawType::Other]), filterProfile()))->toBeTrue();
});

it('непустой law_types: проходит только закон из списка', function () {
    $profile = filterProfile(['law_types' => ['fz44']]);
    expect(passes(filterTender(['law_type' => LawType::Fz44]), $profile))->toBeTrue();
    expect(passes(filterTender(['law_type' => LawType::Fz223]), $profile))->toBeFalse();
});

it('other проходит только при пустом law_types', function () {
    $profile = filterProfile(['law_types' => ['fz44', 'fz223', 'commercial']]);
    expect(passes(filterTender(['law_type' => LawType::Other]), $profile))->toBeFalse();
});

it('регион из excluded_regions отсекает', function () {
    $profile = filterProfile(['excluded_regions' => ['Тверь']]);
    expect(passes(filterTender(['region' => 'Тверь']), $profile))->toBeFalse();
    expect(passes(filterTender(['region' => 'Москва']), $profile))->toBeTrue();
});

it('ценовой фильтр срабатывает только когда заданы ОБЕ границы и price NOT NULL', function () {
    $bounded = filterProfile(['min_price' => '1000000', 'max_price' => '2000000']);
    expect(passes(filterTender(['price' => '1500000']), $bounded))->toBeTrue();
    expect(passes(filterTender(['price' => '2500000']), $bounded))->toBeFalse();
    expect(passes(filterTender(['price' => '500000']), $bounded))->toBeFalse();
    // price NULL не отсекается жёстким фильтром
    expect(passes(filterTender(['price' => null]), $bounded))->toBeTrue();
    // задана только одна граница → ценового жёсткого фильтра нет
    $oneSide = filterProfile(['min_price' => '1000000', 'max_price' => null]);
    expect(passes(filterTender(['price' => '500000']), $oneSide))->toBeTrue();
});

it('источник вне включённых отсекает', function () {
    expect(passes(filterTender(['source_id' => 1]), filterProfile(), [1, 2]))->toBeTrue();
    expect(passes(filterTender(['source_id' => 9]), filterProfile(), [1, 2]))->toBeFalse();
});

it('manual-закупка чужого владельца отсекается; своя — проходит', function () {
    $profile = filterProfile(['company_id' => 7]);
    expect(passes(filterTender(['owner_company_id' => 7]), $profile))->toBeTrue();
    expect(passes(filterTender(['owner_company_id' => 99]), $profile))->toBeFalse();
    // глобальная (owner null) проходит
    expect(passes(filterTender(['owner_company_id' => null]), $profile))->toBeTrue();
});
