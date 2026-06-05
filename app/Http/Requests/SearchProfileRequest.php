<?php

namespace App\Http\Requests;

use App\Enums\LawType;
use App\Models\SearchProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-company');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'min_price' => $this->input('min_price') === '' ? null : $this->input('min_price'),
            'max_price' => $this->input('max_price') === '' ? null : $this->input('max_price'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('search_profiles', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($this->route('profile')),
            ],
            'law_types' => ['array'],
            'law_types.*' => [Rule::enum(LawType::class)],
            'regions' => ['array'],
            'regions.*' => ['string', 'max:255'],
            'excluded_regions' => ['array'],
            'excluded_regions.*' => ['string', 'max:255'],
            'okpd2_codes' => ['array'],
            'okpd2_codes.*' => ['string', 'max:20'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'max_price' => array_filter(['nullable', 'numeric', 'min:0', 'max:999999999999', $this->filled('min_price') ? 'gte:min_price' : null]),
            'deadline_min_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notification_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'exclude_on_negative_in_title' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->route('profile') !== null) {
                    return; // лимит — только на создание
                }

                $company = $this->user()->company;
                $plan = $company->activeSubscription()?->plan;

                if ($plan === null) {
                    $validator->errors()->add('name', 'У компании нет активной подписки — создание направлений недоступно.');

                    return;
                }

                $count = SearchProfile::query()->count(); // tenant-scope уже ограничил компанией
                if ($count >= $plan->max_search_profiles) {
                    $validator->errors()->add('name', "Достигнут лимит тарифа: {$plan->max_search_profiles} направлений.");
                }
            },
        ];
    }
}
