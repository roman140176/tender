<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenderAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-tender-workflow') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'assigned_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
        ];
    }
}
