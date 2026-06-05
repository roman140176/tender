<?php

namespace Database\Factories;

use App\Enums\SourceConnectionType;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(1),
            'name' => fake()->company(),
            'connection_type' => SourceConnectionType::Manual,
        ];
    }
}
