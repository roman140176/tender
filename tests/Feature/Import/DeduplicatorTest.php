<?php

use App\Models\Company;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use App\Services\Import\ImportRowCollector;
use App\Services\Import\NormalizedTender;
use App\Services\Import\Normalizer;

function manualSource(): Source
{
    return Source::factory()->create(['code' => 'manual-'.fake()->unique()->numerify('##')]);
}

function normalized(array $overrides = []): NormalizedTender
{
    return app(Normalizer::class)->normalize(array_merge([
        'title' => 'Поставка кранов',
        'customer_name' => 'ООО «Ромашка»',
        'price' => '1500000',
        'published_at' => '01.06.2026',
        'source_url' => 'https://example.com/t/1',
    ], $overrides));
}

it('создаёт новую закупку с owner и manual-инвариантом', function () {
    $company = Company::factory()->create();
    $source = manualSource();

    $result = app(Deduplicator::class)->process(normalized(), $source->id, $company->id);

    expect($result->status)->toBe('created');
    $tender = Tender::sole();
    expect($tender->owner_company_id)->toBe($company->id)
        ->and($tender->external_id)->toHaveLength(64)
        ->and($tender->price)->toBe('1500000.00')
        ->and($tender->first_seen_at)->not->toBeNull();
});

it('manual без owner_company_id запрещён', function () {
    app(Deduplicator::class)->process(normalized(), manualSource()->id, null);
})->throws(InvalidArgumentException::class);

it('идентичная строка повторно — unchanged, last_seen_at НЕ обновляется refresh(), но двигается через flushLastSeen()', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);
    $normalizer = app(Normalizer::class);

    $dedup->process(normalized(), $source->id, $company->id);
    $tender = Tender::sole();
    $tender->forceFill(['last_seen_at' => now()->subDay()])->save();
    $oldLastSeen = $tender->last_seen_at->toDateTimeString();

    $result = $dedup->process(normalized(), $source->id, $company->id);

    expect($result->status)->toBe('unchanged')
        ->and(Tender::count())->toBe(1);

    // refresh() НЕ вызывает save() при unchanged — last_seen_at не изменился
    expect(Tender::sole()->last_seen_at->toDateTimeString())->toBe($oldLastSeen);

    // flushLastSeen() через collector обновит батчем
    $collector = new ImportRowCollector($normalizer, $dedup, $source->id, $company, null);
    $collector->unchangedIds = [Tender::sole()->id];
    $this->travelTo(now()->addMinutes(5));
    $collector->flushLastSeen();

    expect(Tender::sole()->last_seen_at->isToday())->toBeTrue();
});

it('изменение цены и дедлайна — updated с diff в tender_changes', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(), $source->id, $company->id);
    // Та же закупка (тот же source_url), новые цена и дедлайн
    $result = $dedup->process(
        normalized(['price' => '1 700 000', 'deadline_at' => '25.06.2026']),
        $source->id,
        $company->id,
    );

    expect($result->status)->toBe('updated')->and(Tender::count())->toBe(1);

    $changes = Tender::sole()->changeLogs()->pluck('new_value', 'field');
    expect($changes)->toHaveKey('price')
        ->and($changes['price'])->toBe('1700000.00')
        ->and($changes)->toHaveKey('deadline_at');
});

it('одинаковый content_hash без url склеивается (3-й уровень каскада)', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(['source_url' => '']), $source->id, $company->id);
    $result = $dedup->process(normalized(['source_url' => '']), $source->id, $company->id);

    expect($result->status)->toBe('unchanged')->and(Tender::count())->toBe(1);
});

it('приватность: одинаковая manual-закупка двух компаний НЕ склеивается', function () {
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(), $source->id, Company::factory()->create()->id);
    $dedup->process(normalized(), $source->id, Company::factory()->create()->id);

    expect(Tender::count())->toBe(2);
});

it('уровень 1: тот же номер закупки склеивает при изменившемся url и контенте', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(['number' => 'N-100']), $source->id, $company->id);
    $result = $dedup->process(
        normalized(['number' => 'N-100', 'source_url' => 'https://example.com/t/OTHER', 'price' => '9 999 999']),
        $source->id,
        $company->id,
    );

    expect($result->status)->toBe('updated')->and(Tender::count())->toBe(1);
});

it('уровень 2: тот же source_url склеивает при изменившемся контенте (hash и synthetic id другие)', function () {
    $company = Company::factory()->create();
    $source = manualSource();
    $dedup = app(Deduplicator::class);

    $dedup->process(normalized(), $source->id, $company->id);
    // цена меняется => content_hash И синтетический external_id другие; склейка возможна ТОЛЬКО по url
    $result = $dedup->process(normalized(['price' => '2 000 000']), $source->id, $company->id);

    expect($result->status)->toBe('updated')
        ->and(Tender::count())->toBe(1)
        ->and(Tender::sole()->price)->toBe('2000000.00');
});
