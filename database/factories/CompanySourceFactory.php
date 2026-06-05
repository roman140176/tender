<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySource;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySource>
 */
class CompanySourceFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'source_id' => Source::factory()];
    }
}
