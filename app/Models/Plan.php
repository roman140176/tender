<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
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
