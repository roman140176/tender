<?php

namespace Database\Factories;

use App\Enums\LawType;
use App\Enums\TenderSourceStatus;
use App\Models\Source;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tender>
 */
class TenderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => Source::factory(),
            'external_id' => fake()->unique()->uuid(),
            'source_url' => fake()->url(),
            'title' => 'Поставка '.fake()->words(3, true),
            'description' => fake()->sentence(12),
            'customer_name' => 'ГБУ «'.fake()->company().'»',
            'region' => 'Москва',
            'price' => fake()->randomFloat(2, 100_000, 50_000_000),
            'law_type' => LawType::Fz44,
            'source_status' => TenderSourceStatus::Active,
            'published_at' => now()->subDays(2),
            'deadline_at' => now()->addDays(10),
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
