<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Synonym extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variants' => 'array'];
    }
}
