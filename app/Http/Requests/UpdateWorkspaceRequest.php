<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'default_language' => ['required', Rule::in(['en', 'ur', 'hi'])],
            'organization_id' => ['prohibited'],
        ];
    }
}
