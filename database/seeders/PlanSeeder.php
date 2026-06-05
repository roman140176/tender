<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'basic', 'name' => 'Базовый', 'max_search_profiles' => 3, 'max_sources' => 5, 'max_users' => 3,
                'allow_schedules' => false, 'allow_ai' => false, 'allow_api' => false],
            ['code' => 'standard', 'name' => 'Стандартный', 'max_search_profiles' => 10, 'max_sources' => 10, 'max_users' => 10,
                'allow_schedules' => true, 'allow_ai' => true, 'allow_api' => false, 'max_ai_calls_per_month' => 500],
            ['code' => 'professional', 'name' => 'Профессиональный', 'max_search_profiles' => 50, 'max_sources' => 100, 'max_users' => 50,
                'allow_schedules' => true, 'allow_ai' => true, 'allow_api' => true, 'max_ai_calls_per_month' => 5000],
        ] as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
