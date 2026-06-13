<?php

namespace App\Http\Requests;

use App\Enums\WorkflowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-tender-workflow') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(WorkflowStatus::class)]];
    }
}
