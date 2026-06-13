<?php

namespace App\Models;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use Database\Factories\TenderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Истинные типы кастов (Larastan выводит из jsonb/enum/datetime-колонок неверно).
 *
 * @property numeric-string|null $price
 * @property LawType $law_type
 * @property TenderSourceStatus $source_status
 * @property Carbon|null $published_at
 * @property Carbon|null $deadline_at
 * @property list<string>|null $okpd2
 * @property list<string>|null $ktru
 * @property array<string, mixed>|null $extra
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class Tender extends Model
{
    /** @use HasFactory<TenderFactory> */
    use HasFactory;

    /**
     * ГЛОБАЛЬНАЯ таблица: БЕЗ BelongsToCompany. Доступ компаний к закупкам —
     * только через company_tenders/tender_matches (спека §5/§11).
     * search_vector — generated-колонка PG, в неё нельзя писать.
     */
    protected $guarded = ['id', 'search_vector'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'law_type' => LawType::class,
            'source_status' => TenderSourceStatus::class,
            'published_at' => 'datetime',
            'deadline_at' => 'datetime',
            'okpd2' => 'array',
            'ktru' => 'array',
            'extra' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasMany<TenderAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TenderAttachment::class);
    }

    /** @return HasMany<TenderChange, $this> */
    public function changeLogs(): HasMany
    {
        return $this->hasMany(TenderChange::class);
    }
}
