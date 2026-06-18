<?php

namespace App\Jobs;

use App\Adapters\Eis\CollectParams;
use App\Adapters\Eis\EisAdapter;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Source;
use App\Models\SourceCollectRun;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CollectSourceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public int $sourceId,
        public string $trigger,
    ) {
        $this->onConnection('redis-long')->onQueue('collect');
    }

    public function handle(): void
    {
        $lock = Cache::lock('collect:source:'.$this->sourceId, 10);

        if (! $lock->get()) {
            // Не смогли захватить лок — другой инстанс уже запускается
            return;
        }

        try {
            // Re-run blocking: если уже есть Running ран — выходим
            if (SourceCollectRun::where('source_id', $this->sourceId)
                ->where('status', RunStatus::Running)
                ->exists()
            ) {
                return;
            }

            $run = SourceCollectRun::create([
                'source_id' => $this->sourceId,
                'company_id' => null,
                'trigger' => RunTrigger::from($this->trigger),
                'status' => RunStatus::Running,
                'started_at' => now(),
                'fetched_count' => 0,
                'new_count' => 0,
                'updated_count' => 0,
                'error_count' => 0,
            ]);
        } finally {
            $lock->release();
        }

        // Захватываем скаляры заранее для замыканий батча (сериализуемость)
        // и для F3 catch-блока ниже.
        $runId = $run->id;
        $sourceId = $this->sourceId;

        // F3: после create($run) (Running) любое исключение в построении/диспатче
        // батча оставило бы ран навсегда в Running → re-run guard заблокировал бы
        // все будущие сборы. Помечаем ран Error и пробрасываем (джоб фейлится штатно).
        try {
            $source = Source::findOrFail($this->sourceId);

            /** @var EisAdapter $adapter */
            $adapter = app($source->adapter_class);

            $since = $source->last_collected_at !== null
                ? CarbonImmutable::parse($source->last_collected_at)->utc()
                : CarbonImmutable::now('UTC')->subDays((int) config('eis.backfill_days', 30));

            $until = CarbonImmutable::now('UTC');

            $params = new CollectParams(
                since: $since,
                until: $until,
                regions: array_values(config('eis.regions', [])),
                subsystemTypes: array_values(config('eis.subsystems', [])),
            );

            $packages = iterator_to_array($adapter->listPackages($params));

            $untilStr = $until->toDateTimeString();
            $startedAtIso = CarbonImmutable::parse($run->started_at)->toIso8601String();
            $storageDisk = config('eis.storage_disk', 'local');

            if (empty($packages)) {
                // Финализируем немедленно (error_count=0 → last_collected_at двигается)
                self::finalizeRun($runId, $sourceId, $untilStr);

                CollectMatchFanoutJob::dispatch($startedAtIso);

                return;
            }

            $jobs = array_map(
                fn ($ref) => new DownloadPackageJob($runId, $sourceId, $ref),
                $packages,
            );

            Bus::batch($jobs)
                ->name('collect:'.$sourceId)
                ->onQueue('collect')
                ->then(function (Batch $batch) use ($runId, $sourceId, $untilStr): void {
                    // static-вызов: замыкание захватывает только скаляры (сериализуемость).
                    CollectSourceJob::finalizeRun($runId, $sourceId, $untilStr);
                })
                ->catch(function (Batch $batch, Throwable $e) use ($runId): void {
                    SourceCollectRun::whereKey($runId)->update([
                        'status' => RunStatus::Error,
                        'finished_at' => now(),
                        'log' => $e->getMessage(),
                    ]);
                })
                ->finally(function (Batch $batch) use ($runId, $startedAtIso, $storageDisk): void {
                    Storage::disk($storageDisk)->deleteDirectory('eis/'.$runId);
                    CollectMatchFanoutJob::dispatch($startedAtIso);
                })
                ->dispatch();
        } catch (Throwable $e) {
            SourceCollectRun::whereKey($runId)->update([
                'status' => RunStatus::Error,
                'finished_at' => now(),
                'log' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Финализирует ран: status=Ok + finished_at=now().
     *
     * F4: last_collected_at двигается ТОЛЬКО при error_count=0. Если в чанках
     * были ошибки (error_count>0) — окно НЕ продвигаем, чтобы следующий
     * инкрементальный сбор пересканировал пропущенные/ошибочные извещения.
     *
     * Static (без $this) — чтобы замыкания батча захватывали только скаляры.
     */
    public static function finalizeRun(int $runId, int $sourceId, string $untilStr): void
    {
        SourceCollectRun::whereKey($runId)->update([
            'status' => RunStatus::Ok,
            'finished_at' => now(),
        ]);

        if (SourceCollectRun::whereKey($runId)->value('error_count') === 0) {
            Source::whereKey($sourceId)->update([
                'last_collected_at' => $untilStr,
            ]);
        }
    }
}
