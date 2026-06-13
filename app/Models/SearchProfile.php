<?php

namespace App\Models;

use App\Enums\KeywordType;
use App\Enums\ScheduleType;
use App\Tenancy\BelongsToCompany;
use Database\Factories\SearchProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Истинные типы кастов (Larastan выводит из jsonb/enum/datetime-колонок неверно).
 *
 * @property bool $is_active
 * @property list<string>|null $law_types
 * @property list<string>|null $regions
 * @property list<string>|null $excluded_regions
 * @property list<string>|null $okpd2_codes
 * @property numeric-string|null $min_price
 * @property numeric-string|null $max_price
 * @property bool $exclude_on_negative_in_title
 * @property array<string, mixed>|null $scoring_weights
 * @property ScheduleType $schedule_type
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 */
class SearchProfile extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SearchProfileFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * ВНИМАНИЕ: cast 'decimal:2' возвращает string — сравнения цен в матчере
     * делать через bccomp()/приведение, не строковыми операторами.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'law_types' => 'array',
            'regions' => 'array',
            'excluded_regions' => 'array',
            'okpd2_codes' => 'array',
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'deadline_min_days' => 'integer',
            'notification_threshold' => 'integer',
            'exclude_on_negative_in_title' => 'boolean',
            'scoring_weights' => 'array',
            'schedule_type' => ScheduleType::class,
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Keyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function canActivate(): bool
    {
        return $this->keywords()
            ->whereIn('type', KeywordType::positiveCases())
            ->exists();
    }
}
