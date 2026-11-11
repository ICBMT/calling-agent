<?php

namespace App\Services\Voice;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Data\ResolvedProvider;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ProviderUnavailable;
use Illuminate\Support\Facades\Gate;

final class VoiceSelection
{
    public function __construct(private readonly ProviderManager $providers) {}

    /** All media choices come from the same authorized M2 routing decision. Never browser IDs. */
    public function resolve(Organization $organization, User $actor): ResolvedProvider
    {
        Gate::forUser($actor)->authorize('manage', $organization);
        try {
            $resolved = $this->providers->resolveNativeRealtime($organization, $actor);
        } catch (ProviderUnavailable) {
            throw new VoiceFailure('provider_not_ready');
        }
        $supportedProviders = config('voice.native.providers', [config('voice.native.provider')]);
        if (! in_array($resolved->provider, $supportedProviders, true)) {
            throw new VoiceFailure('unsupported_provider');
        }
        $providerModels = $resolved->provider === 'openai'
            ? config('voice.native.models')
            : config("voice.native.supported.{$resolved->provider}.models", []);
        if (! in_array($resolved->model, $providerModels, true)) {
            throw new VoiceFailure('unsupported_provider');
        }

        return $resolved;
    }

    public function choices(Organization $organization, User $actor): array
    {
        $resolved = $this->resolve($organization, $actor);
        $configuration = ProviderConfiguration::query()->findOrFail($resolved->configurationId);
        $catalog = $configuration->catalog;
        $providerModels = $resolved->provider === 'openai'
            ? config('voice.native.models')
            : config("voice.native.supported.{$resolved->provider}.models", []);
        $languages = $resolved->provider === 'openai'
            ? config('voice.native.languages')
            : config("voice.native.supported.{$resolved->provider}.languages", config('voice.native.languages'));
        $transcriptionModel = $resolved->provider === 'openai'
            ? config('voice.native.transcription_model')
            : config("voice.native.supported.{$resolved->provider}.transcription_model", config('voice.native.transcription_model'));

        $models = [];
        $voices = [];
        foreach ($catalog['models'] ?? [] as $model) {
            if (! in_array($model['id'], $providerModels, true) || ($model['streaming'] ?? null) !== true) {
                continue;
            }
            $modelVoices = array_values(array_filter($model['voice_ids'] ?? [], fn ($voice) => Catalog::validSelection($catalog, Capability::Realtime, $model['id'], $voice, $resolved->provider)));
            if ($modelVoices !== []) {
                $models[] = [
                    'id' => $model['id'],
                    'name' => $model['name'] ?? $model['id'],
                    'voices' => $modelVoices,
                    'voice_ids' => $modelVoices,
                ];
            }
        }
        foreach ($catalog['voices'] ?? [] as $voice) {
            if (Catalog::validSelection($catalog, Capability::Realtime, $resolved->model, $voice['id'], $resolved->provider)) {
                $voices[] = ['id' => $voice['id'], 'name' => $voice['name'] ?? $voice['id']];
            }
        }
        if ($models === []) {
            throw new VoiceFailure('invalid_selection');
        }

        $providerName = match ($resolved->provider) {
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI',
            default => ucfirst($resolved->provider),
        };

        return [
            'provider' => $providerName,
            'provider_id' => $resolved->provider,
            'architecture' => 'native_realtime',
            'models' => $models,
            'voices' => $voices,
            'model' => $resolved->model,
            'voice' => $resolved->voice,
            'default' => [
                'model' => $resolved->model,
                'voice' => $resolved->voice,
                'language' => 'en',
            ],
            'languages' => $languages,
            'transcription_model' => $transcriptionModel,
            'scope' => $configuration->scope === 'platform' ? 'Shared platform default' : 'Workspace default / explicit fallback',
        ];
    }

    public function validateChoice(ProviderConfiguration $configuration, string $model, string $voice, string $language): void
    {
        $catalog = $configuration->catalog;
        $provider = $configuration->provider;
        $supportedProviders = config('voice.native.providers', [config('voice.native.provider')]);
        $providerModels = $provider === 'openai'
            ? config('voice.native.models')
            : config("voice.native.supported.{$provider}.models", []);
        $languages = $provider === 'openai'
            ? config('voice.native.languages')
            : config("voice.native.supported.{$provider}.languages", config('voice.native.languages'));

        if (! in_array($provider, $supportedProviders, true) || ! $configuration->readyForResolution()
            || ! in_array($model, $providerModels, true)
            || ! array_key_exists($language, $languages)
            || ! is_array($catalog) || (Catalog::selectedModel($catalog, $model)['streaming'] ?? null) !== true
            || ! Catalog::validSelection($catalog, Capability::Realtime, $model, $voice, $provider)) {
            throw new VoiceFailure('invalid_selection');
        }
    }

    /** Reauthorize current persisted membership, draft, route and credential version on every lease. */
    public function authorizeSession(VoiceSession $session): ResolvedProvider
    {
        $actor = User::query()->find($session->user_id);
        $organization = Organization::query()->find($session->organization_id);
        $agent = $organization?->agents()->find($session->agent_id);
        if (! $actor || ! $organization || ! $agent || ! $actor->hasOrganizationRole($organization, ['owner', 'admin'])) {
            throw new VoiceFailure('access_revoked');
        }
        $resolved = $this->resolve($organization, $actor);
        $configuration = ProviderConfiguration::query()->find($resolved->configurationId);
        if (! $configuration || $resolved->configurationId !== $session->provider_configuration_id
            || $configuration->credential_version !== $session->credential_version
            || $configuration->revision !== $session->configuration_revision) {
            throw new VoiceFailure('access_revoked');
        }
        $snapshot = $session->configuration_snapshot;
        if (! is_array($snapshot)) {
            throw new VoiceFailure('access_revoked');
        }
        $this->validateChoice($configuration, $snapshot['model'], $snapshot['voice'], $snapshot['language']);

        return $resolved;
    }

    public function snapshot(Agent $agent, string $model, string $voice, string $language, ?string $provider = null): array
    {
        $resolvedProvider = $provider ?? config('voice.native.provider');
        $transcriptionModel = config("voice.native.supported.{$resolvedProvider}.transcription_model", config('voice.native.transcription_model'));

        return [
            'v' => 1, 'provider' => $resolvedProvider, 'architecture' => 'native_realtime',
            'model' => $model, 'voice' => $voice, 'language' => $language,
            'transcription_model' => $transcriptionModel,
            'instructions' => $agent->instructions, 'greeting' => $agent->greeting ?? '',
            'personality' => $agent->personality,
        ];
    }
}
