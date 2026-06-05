<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-company');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'regex:/^\d{10}(\d{2})?$/'],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'industries' => ['array'],
            'industries.*' => ['string', 'max:255'],
            'products' => ['array'],
            'products.*' => ['string', 'max:255'],
            'services' => ['array'],
            'services.*' => ['string', 'max:255'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return ['inn.regex' => 'ИНН — 10 цифр (юрлицо) или 12 цифр (ИП).'];
    }
}
