<?php

namespace App\Models;

use App\Enums\SourceConnectionType;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'connection_type' => SourceConnectionType::class,
            'is_active' => 'boolean',
            'default_config' => 'array',
            'last_collected_at' => 'datetime',
        ];
    }

    /** @return HasMany<CompanySource, $this> */
    public function companySources(): HasMany
    {
        return $this->hasMany(CompanySource::class);
    }
}
