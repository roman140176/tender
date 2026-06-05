<?php

namespace App\Models;

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Tenancy\BelongsToCompany;
use Database\Factories\TenderMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenderMatch extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<TenderMatchFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'level' => RelevanceLevel::class,
            'reason' => 'array',
            'matched_keywords' => 'array',
            'matched_negative_keywords' => 'array',
            'risk_flags' => 'array',
            'action_recommendation' => ActionRecommendation::class,
            'ai_assessment' => 'array',
        ];
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    /** @return BelongsTo<SearchProfile, $this> */
    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }
}
