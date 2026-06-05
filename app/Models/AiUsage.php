<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    use BelongsToCompany;

    protected $table = 'ai_usage';

    protected $guarded = ['id'];
}
