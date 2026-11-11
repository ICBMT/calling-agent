<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'purpose' => ['required', Rule::in(['receptionist', 'support', 'appointments', 'sales', 'custom'])],
            'description' => ['nullable', 'string', 'max:500'],
            'personality' => ['required', Rule::in(['friendly', 'professional', 'empathetic', 'concise'])],
            'language' => ['required', Rule::in(['en', 'ur', 'hi'])],
            'instructions' => ['required', 'string', 'min:20', 'max:12000'],
            'greeting' => ['nullable', 'string', 'max:500'],
            'organization_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
