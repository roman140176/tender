<?php

use App\Enums\LawType;
use App\Enums\SourceConnectionType;
use App\Enums\TenderSourceStatus;
use App\Models\Company;
use App\Models\Source;
use App\Models\Tender;
use App\Services\Import\Canonicalizer;
use App\Services\Import\Deduplicator;
use App\Services\Import\ImportRowCollector;
use App\Services\Import\ManualImportService;
use App\Services\Import\NormalizedTender;
use App\Services\Import\Normalizer;
use Carbon\CarbonImmutable;
use Database\Seeders\SourceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// ── helpers ──────────────────────────────────────────────────────────────────

function eisSource(): Source
{
    return Source::factory()->create([
        'code' => 'eis-ls-'.fake()->unique()->numerify('###'),
        'connection_type' => SourceConnectionType::OpenData,
    ]);
}

function ntForLastSeen(string $number, string $price = '500000.00'): NormalizedTender
{
    $title = 'Закупка для last_seen_at тест';
    $pub = '2026-06-15';
    $hash = Canonicalizer::contentHash($title, 'Заказчик ООО', '7701230001', $pub, $price);

    return new NormalizedTender(
        title: $title,
        description: null,
        customerName: 'Заказчик ООО',
        customerInn: '7701230001',
        region: '77',
        price: $price,
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse($pub)->utc(),
        deadlineAt: null,
        sourceUrl: "https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber={$number}",
        number: $number,
        okpd2: ['32.50.13'],
        contentHash: $hash,
        ktru: ['32.50.13.190-00000001'],
        extra: ['rootElement' => 'fcsNotificationEF'],
    );
}

// ── тесты ────────────────────────────────────────────────────────────────────

it('unchanged в refresh() НЕ делает SQL UPDATE', function () {
    $source = eisSource();
    $dedup = app(Deduplicator::class);
    $n = ntForLastSeen('N-LS-001');

    $dedup->process($n, $source->id, null); // create
    $tender = Tender::sole();
    $oldLastSeen = $tender->last_seen_at;

    // Перематываем время вперёд, чтобы now() дал другое значение
    $this->travelTo(now()->addMinutes(10));

    DB::enableQueryLog();
    $result = $dedup->process($n, $source->id, null); // unchanged
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($result->status)->toBe('unchanged');

    // Ни одного UPDATE tenders не должно быть
    $updateQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'update') && str_contains($q['query'], 'tenders'));
    expect($updateQueries)->toBeEmpty();

    // last_seen_at не изменился (save() не вызывался)
    expect(Tender::sole()->last_seen_at->toDateTimeString())
        ->toBe($oldLastSeen->toDateTimeString());
});

it('flushLastSeen() батч-обновляет last_seen_at для unchanged', function () {
    $source = eisSource();
    $dedup = app(Deduplicator::class);

    $n1 = ntForLastSeen('N-LS-002');
    $n2 = ntForLastSeen('N-LS-003');

    $dedup->process($n1, $source->id, null); // create
    $dedup->process($n2, $source->id, null); // create

    // Отматываем время назад — last_seen_at устарел
    Tender::query()->update(['last_seen_at' => now()->subDay()]);

    $normalizer = app(Normalizer::class);
    $company = Company::factory()->create();  // collector требует Company, но owner=null для eis

    // Используем ImportRowCollector напрямую для eis (owner_company_id = null — недопустимо для manual,
    // но collector не проверяет это сам; deduplicator проверяет только для manual-source).
    // Создадим collector с fakeCompany — его id не используется для eis (process получит null).
    // Для чистоты теста вызываем deduplicator напрямую и вручную наполняем unchangedIds.
    $collector = new ImportRowCollector($normalizer, $dedup, $source->id, $company, null);

    // Симулируем accept() для двух unchanged строк — вызываем process через collector
    // Collector передаёт $company->id как ownerCompanyId — это сломает eis-источник (не manual),
    // поэтому тестируем flushLastSeen() напрямую через reflection или через публичный интерфейс.
    // Устанавливаем unchangedIds вручную через Closure bind (readonly свойство — public array).

    $ids = Tender::pluck('id')->toArray();
    $collector->unchangedIds = $ids;

    $this->travelTo(now()->addMinutes(5));
    $expectedTime = now()->toDateTimeString();

    $collector->flushLastSeen();

    foreach (Tender::all() as $t) {
        expect($t->last_seen_at->toDateTimeString())->toBe($expectedTime);
    }
    expect($collector->unchangedIds)->toBeEmpty();
});

it('ktru и extra сохраняются при create', function () {
    $source = eisSource();
    $dedup = app(Deduplicator::class);
    $n = ntForLastSeen('N-LS-004');

    $dedup->process($n, $source->id, null);

    $tender = Tender::sole();
    expect($tender->ktru)->toBe(['32.50.13.190-00000001'])
        ->and($tender->extra)->toHaveKey('rootElement', 'fcsNotificationEF');
});

it('ktru и extra обновляются при updated', function () {
    $source = eisSource();
    $dedup = app(Deduplicator::class);

    $n1 = ntForLastSeen('N-LS-005');
    $dedup->process($n1, $source->id, null); // create с ktru=['32.50.13.190-00000001']

    // Цена изменилась → changed=true → refresh сохранит и новые ktru/extra
    $title = 'Закупка для last_seen_at тест';
    $pub = '2026-06-15';
    $hash2 = Canonicalizer::contentHash($title, 'Заказчик ООО', '7701230001', $pub, '600000.00');

    $n2 = new NormalizedTender(
        title: $title,
        description: null,
        customerName: 'Заказчик ООО',
        customerInn: '7701230001',
        region: '77',
        price: '600000.00',
        currency: 'RUB',
        lawType: LawType::Fz44,
        procedureType: null,
        sourceStatus: TenderSourceStatus::Active,
        publishedAt: CarbonImmutable::parse($pub)->utc(),
        deadlineAt: null,
        sourceUrl: 'https://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber=N-LS-005',
        number: 'N-LS-005',
        okpd2: ['32.50.13'],
        contentHash: $hash2,
        ktru: ['32.50.13.190-00000999'],
        extra: ['rootElement' => 'fcsNotificationEFCancel'],
    );

    $r = $dedup->process($n2, $source->id, null);

    expect($r->status)->toBe('updated');
    $tender = Tender::sole();
    expect($tender->ktru)->toBe(['32.50.13.190-00000999'])
        ->and($tender->extra)->toHaveKey('rootElement', 'fcsNotificationEFCancel');
});

it('ManualImportService вызывает flushLastSeen — last_seen_at двигается при повторном импорте', function () {
    $this->seed(SourceSeeder::class);

    $company = Company::factory()->create();
    $file = fn () => new UploadedFile(
        base_path('tests/Fixtures/import.csv'),
        'import.csv',
        'text/csv',
        test: true,
    );

    $this->travelTo(now()->setTime(9, 0));
    app(ManualImportService::class)->import($file(), $company);

    $firstLastSeen = Tender::first()->last_seen_at->toDateTimeString();

    // Второй импорт тех же данных — строки будут unchanged
    $this->travelTo(now()->addHours(2));
    app(ManualImportService::class)->import($file(), $company);

    $secondLastSeen = Tender::first()->last_seen_at->toDateTimeString();

    // last_seen_at должен продвинуться (flushLastSeen вызван)
    expect($secondLastSeen)->not->toBe($firstLastSeen);
});
