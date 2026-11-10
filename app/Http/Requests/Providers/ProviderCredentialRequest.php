<?php

namespace App\Http\Requests\Providers;

use App\Models\ProviderConfiguration;
use App\Services\Providers\Capability;
use App\Services\Providers\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProviderCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('provider_platform_scope')
            ? $this->user()->isPlatformProviderOperator()
            : $this->user()->can('manage', $this->attributes->get('organization'));
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $provider = $creating ? $this->input('provider') : ProviderConfiguration::query()
            ->forScope($this->attributes->get('provider_scope_organization'))->findOrFail($this->route('configuration'))->provider;

        return [
            'name' => ['required', 'string', 'max:100'],
            'provider' => $creating ? ['required', Rule::in(array_keys(app(ProviderRegistry::class)->definitions()))] : ['prohibited'],
            'capability' => $creating ? ['required', Rule::enum(Capability::class)] : ['prohibited'],
            'api_key' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:2048', 'regex:/\A[\x21-\x7E]+\z/D'],
            'project_id' => match ($provider) {
                'deepgram' => ['required', 'uuid', 'max:36'],
                'openai' => ['nullable', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_-]+\z/D'],
                default => ['prohibited'],
            },
            'provider_organization' => $provider === 'openai' ? ['nullable', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_-]+\z/D'] : ['prohibited'],
            'enabled' => ['required', 'boolean'],
            'revision' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            ...array_fill_keys(['organization_id', 'scope', 'endpoint', 'base_url', 'credentials', 'connection_status', 'catalog', 'usage_snapshot', 'selected_model', 'selected_voice', 'routing_key', 'routing_role', 'is_platform_admin', 'credential_version'], ['prohibited']),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isMethod('POST') && ! $validator->errors()->has('provider') && ! $validator->errors()->has('capability')) {
                $capability = Capability::tryFrom((string) $this->input('capability'));
                if ($capability && ! app(ProviderRegistry::class)->supports((string) $this->input('provider'), $capability)) {
                    $validator->errors()->add('capability', 'This provider does not support the requested category in Voca. Choose a supported capability.');
                }
            }
        }];
    }

    public function attributes(): array
    {
        return ['name' => 'connection name', 'api_key' => 'API key', 'provider_organization' => 'OpenAI organization ID', 'project_id' => 'project ID'];
    }

    public function messages(): array
    {
        return ['api_key.regex' => 'The API key must be a single token without spaces or control characters.'];
    }
}
