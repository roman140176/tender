<?php

namespace App\Models;

use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Tenancy\BelongsToCompany;
use App\Tenancy\TenantContext;
use Database\Factories\CompanyTenderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class CompanyTender extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CompanyTenderFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'best_level' => RelevanceLevel::class,
            'best_score' => 'integer',
            'first_matched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Матчи той же пары (company, tender). Связь только по tender_id —
     * фильтрацию по company_id даёт tenant-scope TenderMatch.
     * Требует установленного TenantContext — без него бросает RuntimeException.
     *
     * @return HasMany<TenderMatch, $this>
     */
    public function matches(): HasMany
    {
        if (app(TenantContext::class)->id() === null) {
            throw new RuntimeException(
                'CompanyTender::matches() требует установленного TenantContext: '
                .'без него tenant-scope TenderMatch неактивен и связь вернёт матчи всех компаний. '
                .'В джобах используйте явный запрос TenderMatch::where(company_id, ...) или установите контекст.',
            );
        }

        return $this->hasMany(TenderMatch::class, 'tender_id', 'tender_id');
    }
}
