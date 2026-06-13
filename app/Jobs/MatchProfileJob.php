<?php

namespace App\Jobs;

use App\Enums\RunTrigger;
use App\Models\SearchProfile;
use App\Scoring\MatchProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class MatchProfileJob implements ShouldQueue
{
    use Queueable;

    /** Передаём скаляры (не модели) ради безопасной сериализации в очередь. */
    public function __construct(
        public int $searchProfileId,
        public string $trigger,
        public ?string $sinceUpdatedAt = null,
    ) {}

    public function handle(MatchProfileService $service): void
    {
        $profile = SearchProfile::withoutTenancy()
            ->with('keywords')
            ->find($this->searchProfileId);

        if ($profile === null || ! $profile->is_active) {
            return; // профиль удалён или деактивирован между диспатчем и исполнением
        }

        $service->run(
            $profile,
            RunTrigger::from($this->trigger),
            $this->sinceUpdatedAt !== null ? CarbonImmutable::parse($this->sinceUpdatedAt) : null,
        );
    }
}
