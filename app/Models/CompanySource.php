<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Database\Factories\CompanySourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySource extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CompanySourceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * config шифруется (рандомизированный IV): НИКОГДА не использовать
     * в WHERE/JOIN/INDEX — только читать у конкретной записи.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'config' => 'encrypted'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
