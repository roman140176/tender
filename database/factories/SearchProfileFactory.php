<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SearchProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchProfile>
 */
class SearchProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(2, true),
            'notification_threshold' => 60,
            'deadline_min_days' => 0,
            'exclude_on_negative_in_title' => false,
        ];
    }
}
