<?php

use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Enums\LawType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceConnectionType;
use App\Jobs\DownloadPackageJob;
use App\Jobs\ProcessChunkJob;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Database\Seeders\SourceSeeder;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

use function Tests\Support\makeEisArchive;

beforeEach(function () {
    $this->seed(SourceSeeder::class);
    Storage::fake('local');
});

it('скачивает архив и добавляет ProcessChunkJob в батч', function () {
    Bus::fake();

    $source = Source::where('code', 'eis')->firstOrFail();
    $source->adapter_class = EisAdapter::class;
    $source->connection_type = SourceConnectionType::OpenData;
    $source->save();

    $run = SourceCollectRun::create([
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

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/fake/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    // Фейковый gateway: downloadArchive пишет реальный zip-файл
    $fz44Xml = base_path('tests/Fixtures/eis/fz44_notice.xml');
    $fakeArchive = makeEisArchive([$fz44Xml]);

    $gatewayMock = Mockery::mock(EisGateway::class);
    $gatewayMock->shouldReceive('downloadArchive')
        ->once()
        ->withArgs(function (PackageRef $r, string $dest) use ($ref, $fakeArchive) {
            // Скопировать фейковый архив в нужное место
            @mkdir(dirname($dest), 0755, true);
            copy($fakeArchive, $dest);

            return $r->archiveUrl === $ref->archiveUrl;
        });

    $this->app->bind(EisGateway::class, fn () => $gatewayMock);

    // Создаём батч через Bus::batch и сразу вызываем handle
    $batchMock = Mockery::mock(Batch::class);
    $batchMock->shouldReceive('cancelled')->andReturn(false);
    $batchMock->shouldReceive('add')->once()->withArgs(function (array $jobs) {
        return count($jobs) === 1 && $jobs[0] instanceof ProcessChunkJob;
    });

    $job = new DownloadPackageJob($run->id, $source->id, $ref);

    $setBatch = Closure::bind(function () use ($batchMock) {
        $this->fakeBatch = $batchMock;
    }, $job, $job);
    $setBatch();

    $job->handle();

    // Файл должен быть создан на диске
    $expectedPath = 'eis/'.$run->id.'/'.md5($ref->archiveUrl).'.zip';
    Storage::disk('local')->assertExists($expectedPath);
});

it('Batchable: при отменённом батче job выходит досрочно без скачивания', function () {
    $source = Source::where('code', 'eis')->firstOrFail();
    $source->adapter_class = EisAdapter::class;
    $source->save();

    $run = SourceCollectRun::create([
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

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/fake/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $gatewayMock = Mockery::mock(EisGateway::class);
    $gatewayMock->shouldNotReceive('downloadArchive');
    $this->app->bind(EisGateway::class, fn () => $gatewayMock);

    $batchMock = Mockery::mock(Batch::class);
    $batchMock->shouldReceive('cancelled')->andReturn(true);

    $job = new DownloadPackageJob($run->id, $source->id, $ref);
    $setBatch = Closure::bind(function () use ($batchMock) {
        $this->fakeBatch = $batchMock;
    }, $job, $job);
    $setBatch();

    $job->handle();

    Storage::disk('local')->assertMissing('eis/'.$run->id.'/'.md5($ref->archiveUrl).'.zip');
});
