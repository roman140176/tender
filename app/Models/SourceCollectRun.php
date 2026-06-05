<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceCollectRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trigger' => RunTrigger::class,
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
