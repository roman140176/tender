<?php

namespace Database\Factories;

use App\Enums\RelevanceLevel;
use App\Enums\WorkflowStatus;
use App\Models\Company;
use App\Models\CompanyTender;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyTender>
 */
class CompanyTenderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'tender_id' => Tender::factory(),
            'status' => WorkflowStatus::New,
            'best_score' => 0,
            'best_level' => RelevanceLevel::Low,
            'first_matched_at' => now(),
        ];
    }
}
