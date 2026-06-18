<?php

namespace App\Jobs;

use App\Adapters\Eis\EisAdapter;
use App\Enums\LawType;
use App\Models\Source;
use App\Models\SourceCollectRun;
use App\Models\Tender;
use App\Services\Import\Deduplicator;
use App\Services\Import\NormalizationException;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessChunkJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $runId,
        public int $sourceId,
        public string $archivePath,
        public LawType $law,
    ) {
        $this->onConnection('redis-long')->onQueue('collect');
    }

    public function handle(Deduplicator $dedup): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $source = Source::findOrFail($this->sourceId);

        /** @var EisAdapter $adapter */
        $adapter = app($source->adapter_class);

        $fetched = 0;
        $new = 0;
        $updated = 0;
        $errors = 0;
        $unchangedIds = [];

        foreach ($adapter->streamNotices($this->archivePath, $this->law) as $raw) {
            $fetched++;

            try {
                $n = $adapter->normalize($raw);
                $r = $dedup->process($n, $this->sourceId, null, $this->runId);

                match ($r->status) {
                    'created' => $new++,
                    'updated' => $updated++,
                    default => ($unchangedIds[] = $r->tender->id),
                };
            } catch (NormalizationException|QueryException) {
                $errors++;
            }
        }

        if ($unchangedIds !== []) {
            Tender::whereIn('id', $unchangedIds)->update(['last_seen_at' => now()]);
        }

        SourceCollectRun::whereKey($this->runId)->update([
            'fetched_count' => DB::raw('fetched_count + '.(int) $fetched),
            'new_count' => DB::raw('new_count + '.(int) $new),
            'updated_count' => DB::raw('updated_count + '.(int) $updated),
            'error_count' => DB::raw('error_count + '.(int) $errors),
        ]);
    }
}
