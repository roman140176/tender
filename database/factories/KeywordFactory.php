<?php

namespace Database\Factories;

use App\Enums\KeywordType;
use App\Models\Keyword;
use App\Models\SearchProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keyword>
 */
class KeywordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'search_profile_id' => SearchProfile::factory(),
            'value' => fake()->word(),
            'type' => KeywordType::Main,
        ];
    }
}
