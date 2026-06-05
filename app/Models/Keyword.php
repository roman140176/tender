<?php

namespace App\Models;

use App\Enums\KeywordOrigin;
use App\Enums\KeywordType;
use Database\Factories\KeywordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    /** @use HasFactory<KeywordFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => KeywordType::class, 'origin' => KeywordOrigin::class];
    }

    /** @return BelongsTo<SearchProfile, $this> */
    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }
}
