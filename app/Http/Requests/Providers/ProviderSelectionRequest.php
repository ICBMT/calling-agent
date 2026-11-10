<?php

namespace App\Http\Requests\Providers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provider_platform_scope')
            ? $this->user()->isPlatformProviderOperator()
            : $this->user()->can('manage', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'selected_model' => ['required', 'string', 'max:200'],
            'selected_voice' => ['nullable', 'string', 'max:200'],
            'routing_role' => ['required', Rule::in(['none', 'default', 'fallback'])],
            ...array_fill_keys(['organization_id', 'scope', 'api_key', 'credentials', 'provider', 'capability', 'catalog', 'endpoint', 'connection_status', 'routing_key'], ['prohibited']),
        ];
    }
}
