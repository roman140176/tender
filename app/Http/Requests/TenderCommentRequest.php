<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenderCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-tender-workflow') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comment' => ['required', 'string', 'max:5000']];
    }
}
