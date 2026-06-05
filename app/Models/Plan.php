<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allow_schedules' => 'boolean',
            'allow_ai' => 'boolean',
            'allow_api' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
