<?php

namespace App\Jobs;

use App\Adapters\Eis\EisAdapter;
use App\Adapters\Eis\PackageRef;
use App\Models\Source;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DownloadPackageJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public int $runId,
        public int $sourceId,
        public PackageRef $ref,
    ) {
        $this->onConnection('redis-long')->onQueue('collect');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $source = Source::findOrFail($this->sourceId);

        /** @var EisAdapter $adapter */
        $adapter = app($source->adapter_class);

        $disk = config('eis.storage_disk', 'local');
        $relativePath = 'eis/'.$this->runId.'/'.md5($this->ref->archiveUrl).'.zip';
        $dest = Storage::disk($disk)->path($relativePath);

        $dir = dirname($dest);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $adapter->downloadArchive($this->ref, $dest);

        $this->batch()->add([
            new ProcessChunkJob($this->runId, $this->sourceId, $dest, $this->ref->lawType),
        ]);
    }
}
