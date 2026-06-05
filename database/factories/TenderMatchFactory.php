<?php

namespace Database\Factories;

use App\Enums\ActionRecommendation;
use App\Enums\RelevanceLevel;
use App\Models\Company;
use App\Models\SearchProfile;
use App\Models\Tender;
use App\Models\TenderMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenderMatch>
 */
class TenderMatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'tender_id' => Tender::factory(),
            'search_profile_id' => SearchProfile::factory(),
            'score' => 60,
            'level' => RelevanceLevel::Medium,
            'action_recommendation' => ActionRecommendation::ReviewDocs,
        ];
    }
}
