<?php

use App\Adapters\Eis\EisAdapter;
use App\Enums\LawType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\ProcessChunkJob;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use Database\Seeders\SourceSeeder;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Storage;

use function Tests\Support\makeEisArchive;

beforeEach(function () {
    $this->seed(SourceSeeder::class);
    Storage::fake('local');
});

function makeEisSource(): Source
{
    return Source::where('code', 'eis')->firstOrFail();
}

function makeEisRun(Source $source): SourceCollectRun
{
    return SourceCollectRun::create([
        'source_id' => $source->id,
        'company_id' => null,
        'trigger' => RunTrigger::Schedule,
        'status' => RunStatus::Running,
        'started_at' => now(),
        'fetched_count' => 0,
        'new_count' => 0,
        'updated_count' => 0,
        'error_count' => 0,
    ]);
}

it('обрабатывает fz44-архив: создаёт tender и инкрементит счётчики', function () {
    // Для этого теста нужен EisAdapter с реальным mapper, без сети.
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/chunk.zip');
    @mkdir(dirname($zipPath), 0755, true);

    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $archive = makeEisArchive([$fz44Xml]);
    copy($archive, $zipPath);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);
    $job->handle(app(Deduplicator::class));

    expect(Tender::count())->toBe(1);

    $tender = Tender::first();
    expect($tender->number)->toBe('0173200001426000001')
        ->and($tender->law_type)->toBe(LawType::Fz44)
        ->and($tender->owner_company_id)->toBeNull();

    $run->refresh();
    expect($run->fetched_count)->toBe(1)
        ->and($run->new_count)->toBe(1)
        ->and($run->updated_count)->toBe(0)
        ->and($run->error_count)->toBe(0);
});

it('обрабатывает fz223-архив: создаёт tender', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/chunk223.zip');
    @mkdir(dirname($zipPath), 0755, true);

    $fz223Xml = base_path('tests/Fixtures/eis/fz223_notice.xml');
    $archive = makeEisArchive([$fz223Xml]);
    copy($archive, $zipPath);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz223);
    $job->handle(app(Deduplicator::class));

    expect(Tender::count())->toBe(1);

    $tender = Tender::first();
    expect($tender->number)->toBe('32212345678')
        ->and($tender->law_type)->toBe(LawType::Fz223);

    $run->refresh();
    expect($run->new_count)->toBe(1);
});

it('повторный запуск даёт updated/unchanged и обновляет last_seen_at батчем', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/chunk.zip');
    @mkdir(dirname($zipPath), 0755, true);

    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $archive = makeEisArchive([$fz44Xml]);
    copy($archive, $zipPath);

    // первый прогон — создаёт
    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);
    $job->handle(app(Deduplicator::class));

    $tender = Tender::first();
    $firstSeen = $tender->last_seen_at;

    // второй прогон — unchanged, last_seen_at должен обновиться
    $this->travel(5)->seconds();

    $run2 = makeEisRun($source);
    $zipPath2 = Storage::disk('local')->path('eis/'.$run2->id.'/chunk2.zip');
    @mkdir(dirname($zipPath2), 0755, true);
    copy($archive, $zipPath2);

    $job2 = new ProcessChunkJob($run2->id, $source->id, $zipPath2, LawType::Fz44);
    $job2->handle(app(Deduplicator::class));

    $run2->refresh();
    expect($run2->new_count)->toBe(0)
        ->and($run2->updated_count)->toBe(0);

    $tender->refresh();
    expect($tender->last_seen_at->gt($firstSeen))->toBeTrue();
});

it('ошибка нормализации инкрементит error_count, не ломает весь чанк', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    // XML с пустым title — вызовет NormalizationException
    $badXml = tempnam(sys_get_temp_dir(), 'eis_bad_').'.xml';
    file_put_contents($badXml, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ns2:export xmlns:ns2="http://zakupki.gov.ru/oos/export/1">
  <ns2:fcsNotificationEF>
    <purchaseNumber>9999999999999999999</purchaseNumber>
    <purchaseObjectInfo></purchaseObjectInfo>
    <docPublishDate>2026-06-15T10:00:00+03:00</docPublishDate>
    <purchaseResponsible><responsibleOrg>
      <fullName>Тест</fullName><INN>7701111111</INN><regionCode>77</regionCode>
    </responsibleOrg></purchaseResponsible>
    <notificationInfo><procedureInfo><collecting><endDate>2026-06-25T18:00:00+03:00</endDate></collecting></procedureInfo></notificationInfo>
    <lot><maxPrice>100.00</maxPrice></lot>
  </ns2:fcsNotificationEF>
</ns2:export>
XML);

    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/bad.zip');
    @mkdir(dirname($zipPath), 0755, true);
    $archive = makeEisArchive([$badXml]);
    copy($archive, $zipPath);
    @unlink($badXml);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);
    $job->handle(app(Deduplicator::class));

    $run->refresh();
    expect($run->error_count)->toBe(1)
        ->and($run->new_count)->toBe(0)
        ->and(Tender::count())->toBe(0);
});

it('Batchable: при отменённом батче job выходит досрочно', function () {
    $source = makeEisSource();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = makeEisRun($source);

    // Валидный архив: без guard'а создал бы tender (см. тест fz44 выше)
    $zipPath = Storage::disk('local')->path('eis/'.$run->id.'/cancel.zip');
    @mkdir(dirname($zipPath), 0755, true);
    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $archive = makeEisArchive([$fz44Xml]);
    copy($archive, $zipPath);

    $job = new ProcessChunkJob($run->id, $source->id, $zipPath, LawType::Fz44);

    // Подменяем РЕАЛЬНЫЙ seam трейта Batchable ($fakeBatch, не $batch) отменённым батчем
    $batchMock = Mockery::mock(Batch::class);
    $batchMock->shouldReceive('cancelled')->once()->andReturn(true);

    $setCancelledBatch = Closure::bind(function () use ($batchMock) {
        $this->fakeBatch = $batchMock;
    }, $job, $job);
    $setCancelledBatch();

    $job->handle(app(Deduplicator::class));

    // Guard сработал: валидный архив НЕ обработан, счётчики не тронуты
    expect(Tender::count())->toBe(0);

    $run->refresh();
    expect($run->fetched_count)->toBe(0)
        ->and($run->new_count)->toBe(0);
});
