<?php

namespace App\Models;

use App\Enums\KeywordOrigin;
use App\Enums\KeywordType;
use Database\Factories\KeywordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Истинные типы кастов (Larastan выводит enum-колонки type/origin как string).
 *
 * @property string $value
 * @property KeywordType $type
 * @property KeywordOrigin $origin
 */
class Keyword extends Model
{
    /** @use HasFactory<KeywordFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => KeywordType::class, 'origin' => KeywordOrigin::class];
    }

    /** Схлопывание пробелов/NBSP + trim. Регистр и ё НЕ трогаем (обрабатываются симметрично в матчере). */
    public static function normalizeValue(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @return BelongsTo<SearchProfile, $this> */
    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }
}
