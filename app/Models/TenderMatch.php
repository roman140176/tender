<?php

namespace App\Models;

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Tenancy\BelongsToCompany;
use Database\Factories\TenderMatchFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{value: string, type: string, field: string}> $matched_keywords
 * @property list<array{value: string, type: string, field: string}> $matched_negative_keywords
 * @property list<array{rule: string, points: int, detail: string}> $reason
 * @property list<string> $risk_flags
 */
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
            'risk_flags' => 'array',
            'action_recommendation' => ActionRecommendation::class,
            'ai_assessment' => 'array',
        ];
    }

    /**
     * Попадания ключей хранятся в jsonb (PG переупорядочивает ключи объекта),
     * поэтому при чтении восстанавливаем канонический порядок value → type → field,
     * чтобы payload был стабилен для потребителей и сравнений.
     *
     * @return Attribute<list<array{value: string, type: string, field: string}>, list<array{value: string, type: string, field: string}>>
     */
    protected function matchedKeywords(): Attribute
    {
        return $this->keywordHitPayload();
    }

    /**
     * @return Attribute<list<array{value: string, type: string, field: string}>, list<array{value: string, type: string, field: string}>>
     */
    protected function matchedNegativeKeywords(): Attribute
    {
        return $this->keywordHitPayload();
    }

    /**
     * @return Attribute<list<array{value: string, type: string, field: string}>, list<array{value: string, type: string, field: string}>>
     */
    private function keywordHitPayload(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                /** @var list<array<string, mixed>> $decoded */
                $decoded = $value === null ? [] : (array) json_decode($value, true);

                return array_map(
                    static fn (array $hit): array => [
                        'value' => (string) ($hit['value'] ?? ''),
                        'type' => (string) ($hit['type'] ?? ''),
                        'field' => (string) ($hit['field'] ?? ''),
                    ],
                    $decoded,
                );
            },
            set: fn (array $value): string => (string) json_encode(array_values($value)),
        );
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
