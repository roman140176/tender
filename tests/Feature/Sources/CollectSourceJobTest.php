<?php

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\EisGateway;
use App\Adapters\Eis\PackageRef;
use App\Enums\LawType;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceConnectionType;
use App\Jobs\CollectMatchFanoutJob;
use App\Jobs\CollectSourceJob;
use App\Jobs\DownloadPackageJob;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Database\Seeders\SourceSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(SourceSeeder::class);
    Storage::fake('local');
    config(['eis.regions' => ['77'], 'eis.subsystems' => ['PRIZ']]);
});

function eisSourceWithAdapter(): Source
{
    $source = Source::where('code', 'eis')->firstOrFail();
    $source->adapter_class = EisAdapter::class;
    $source->connection_type = SourceConnectionType::OpenData;
    $source->save();

    return $source;
}

function fakeGatewayWithPackages(array $packages = []): EisGateway
{
    $mock = Mockery::mock(EisGateway::class);
    $mock->shouldReceive('listPackages')->andReturn($packages);

    return $mock;
}

// ─── Тест 1: пустой listPackages → run Ok + last_collected_at + CollectMatchFanoutJob ───

it('пустой listPackages завершает run Ok и диспатчит CollectMatchFanoutJob', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();
    $gateway = fakeGatewayWithPackages([]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->company_id)->toBeNull();

    $source->refresh();
    expect($source->last_collected_at)->not->toBeNull();

    Bus::assertDispatched(CollectMatchFanoutJob::class);
    Bus::assertNotDispatched(DownloadPackageJob::class);
});

// ─── Тест 2: re-run blocking — второй вызов при Running-ране НЕ создаёт второй run ───

it('re-run blocking: второй CollectSourceJob при running-ране не создаёт второй run', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();

    // Создаём уже Running ран вручную
    SourceCollectRun::create([
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

    // Второй запуск
    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    // Должен быть ровно один ран (тот, что создали вручную)
    expect(SourceCollectRun::where('source_id', $source->id)->count())->toBe(1);
    Bus::assertNothingDispatched();
});

// ─── Тест 3: не-пустой listPackages → Bus::batch с DownloadPackageJob ───

it('непустой listPackages создаёт Bus::batch с DownloadPackageJob', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();

    $ref = new PackageRef(
        archiveUrl: 'https://int44.zakupki.gov.ru/fake/archive.zip',
        region: '77',
        subsystemType: 'PRIZ',
        date: '2026-06-15',
        lawType: LawType::Fz44,
    );

    $gateway = fakeGatewayWithPackages([$ref]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Manual->value))->handle();

    // Ран создан со статусом Running (батч ещё идёт — Bus::fake не выполняет батч)
    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->company_id)->toBeNull();

    Bus::assertBatched(function (PendingBatch $batch) {
        return $batch->jobs->filter(fn ($j) => $j instanceof DownloadPackageJob)->count() === 1
            && $batch->queue() === 'collect';
    });
});

// ─── Тест 4: run создаётся с правильным trigger ───

it('run создаётся с trigger из параметра джоба', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();
    $gateway = fakeGatewayWithPackages([]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Manual->value))->handle();

    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->trigger)->toBe(RunTrigger::Manual);
});

// ─── Тест 5: CollectParams строится с backfill из конфига, если last_collected_at null ───

it('CollectParams строится от now()-backfill_days если last_collected_at не задан', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();
    $source->last_collected_at = null;
    $source->save();

    config(['eis.backfill_days' => 7]);

    $capturedParams = null;
    $gateway = Mockery::mock(EisGateway::class);
    $gateway->shouldReceive('listPackages')
        ->withArgs(function (CollectParams $p) use (&$capturedParams) {
            $capturedParams = $p;

            return true;
        })
        ->andReturn([]);
    app()->bind(EisGateway::class, fn () => $gateway);

    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    expect($capturedParams)->not->toBeNull();
    $daysDiff = (int) $capturedParams->since->diffInDays($capturedParams->until);
    expect($daysDiff)->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(8);
    expect($capturedParams->regions)->toBe(['77']);
    expect($capturedParams->subsystemTypes)->toBe(['PRIZ']);
});

// ─── F3: throw в listPackages помечает run Error (не оставляет Running) ───

it('F3: исключение в listPackages помечает run Error, не оставляя orphan Running', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();

    $gateway = Mockery::mock(EisGateway::class);
    $gateway->shouldReceive('listPackages')->andThrow(new RuntimeException('network down'));
    app()->bind(EisGateway::class, fn () => $gateway);

    expect(fn () => (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle())
        ->toThrow(RuntimeException::class);

    $run = SourceCollectRun::where('source_id', $source->id)->firstOrFail();
    expect($run->status)->toBe(RunStatus::Error)
        ->and($run->status)->not->toBe(RunStatus::Running)
        ->and($run->finished_at)->not->toBeNull();
});

it('F3: после Error-рана re-run guard пропускает новый сбор', function () {
    Bus::fake();

    $source = eisSourceWithAdapter();

    // Первый запуск падает → run Error
    $failing = Mockery::mock(EisGateway::class);
    $failing->shouldReceive('listPackages')->andThrow(new RuntimeException('network down'));
    app()->bind(EisGateway::class, fn () => $failing);

    expect(fn () => (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle())
        ->toThrow(RuntimeException::class);

    expect(SourceCollectRun::where('source_id', $source->id)->count())->toBe(1);

    // Второй запуск с нормальным пустым gateway → re-run guard пропускает (прежний Error)
    $ok = fakeGatewayWithPackages([]);
    app()->bind(EisGateway::class, fn () => $ok);

    (new CollectSourceJob($source->id, RunTrigger::Schedule->value))->handle();

    expect(SourceCollectRun::where('source_id', $source->id)->count())->toBe(2);
});

// ─── F4: finalizeRun не двигает last_collected_at при error_count>0 ───

it('F4: finalizeRun НЕ двигает last_collected_at при error_count>0', function () {
    $source = eisSourceWithAdapter();
    $source->last_collected_at = null;
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
        'error_count' => 2,
    ]);

    CollectSourceJob::finalizeRun($run->id, $source->id, '2026-06-18 10:00:00');

    $run->refresh();
    $source->refresh();

    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->finished_at)->not->toBeNull()
        ->and($source->last_collected_at)->toBeNull();
});

it('F4: finalizeRun двигает last_collected_at при error_count=0', function () {
    $source = eisSourceWithAdapter();
    $source->last_collected_at = null;
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

    CollectSourceJob::finalizeRun($run->id, $source->id, '2026-06-18 10:00:00');

    $run->refresh();
    $source->refresh();

    expect($run->status)->toBe(RunStatus::Ok)
        ->and($run->finished_at)->not->toBeNull()
        ->and($source->last_collected_at?->toDateTimeString())->toBe('2026-06-18 10:00:00');
});
