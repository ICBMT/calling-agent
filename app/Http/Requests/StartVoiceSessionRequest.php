<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartVoiceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        return [
            'agent_id' => ['required', 'integer', 'min:1'],
            'architecture' => ['required', Rule::in(['native_realtime'])],
            'model' => ['required', 'string', 'max:200'],
            'voice' => ['required', 'string', 'max:200'],
            'language' => ['required', Rule::in(array_keys(config('voice.native.languages')))],
            'consent' => ['required', 'accepted'],
            'organization_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'configuration_id' => ['prohibited'], 'provider_configuration_id' => ['prohibited'],
            'api_key' => ['prohibited'], 'token' => ['prohibited'], 'room' => ['prohibited'],
            'instructions' => ['prohibited'], 'greeting' => ['prohibited'],
        ];
    }
}
