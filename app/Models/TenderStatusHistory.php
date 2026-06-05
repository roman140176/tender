<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TenderStatusHistory extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'tender_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->created_at ??= now());
    }
}
