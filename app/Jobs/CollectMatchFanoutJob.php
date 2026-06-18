<?php

namespace App\Jobs;

use App\Enums\RunTrigger;
use App\Models\SearchProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CollectMatchFanoutJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $sinceUpdatedAt = null,
    ) {
        $this->onConnection('redis-long')->onQueue('collect');
    }

    public function handle(): void
    {
        SearchProfile::withoutTenancy()
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $id) => MatchProfileJob::dispatch(
                $id,
                RunTrigger::Schedule->value,
                $this->sinceUpdatedAt,
            ));
    }
}
