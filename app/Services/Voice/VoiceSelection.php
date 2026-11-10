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
        if ($resolved->provider !== config('voice.native.provider') || ! in_array($resolved->model, config('voice.native.models'), true)) {
            throw new VoiceFailure('unsupported_provider');
        }

        return $resolved;
    }

    public function choices(Organization $organization, User $actor): array
    {
        $resolved = $this->resolve($organization, $actor);
        $configuration = ProviderConfiguration::query()->findOrFail($resolved->configurationId);
        $catalog = $configuration->catalog;
        $models = [];
        foreach ($catalog['models'] ?? [] as $model) {
            if (! in_array($model['id'], config('voice.native.models'), true) || ($model['streaming'] ?? null) !== true) {
                continue;
            }
            $voices = array_values(array_filter($model['voice_ids'] ?? [], fn ($voice) => Catalog::validSelection($catalog, Capability::Realtime, $model['id'], $voice, $resolved->provider)));
            if ($voices !== []) {
                $models[] = ['id' => $model['id'], 'voices' => $voices];
            }
        }
        if ($models === []) {
            throw new VoiceFailure('invalid_selection');
        }

        return [
            'provider' => 'OpenAI', 'architecture' => 'native_realtime',
            'models' => $models, 'model' => $resolved->model, 'voice' => $resolved->voice,
            'languages' => config('voice.native.languages'),
            'transcription_model' => config('voice.native.transcription_model'),
            'scope' => $configuration->scope === 'platform' ? 'Shared platform default' : 'Workspace default / explicit fallback',
        ];
    }

    public function validateChoice(ProviderConfiguration $configuration, string $model, string $voice, string $language): void
    {
        $catalog = $configuration->catalog;
        if ($configuration->provider !== config('voice.native.provider') || ! $configuration->readyForResolution()
            || ! in_array($model, config('voice.native.models'), true)
            || ! array_key_exists($language, config('voice.native.languages'))
            || ! is_array($catalog) || (Catalog::selectedModel($catalog, $model)['streaming'] ?? null) !== true
            || ! Catalog::validSelection($catalog, Capability::Realtime, $model, $voice, $configuration->provider)) {
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

    public function snapshot(Agent $agent, string $model, string $voice, string $language): array
    {
        return [
            'v' => 1, 'provider' => 'openai', 'architecture' => 'native_realtime',
            'model' => $model, 'voice' => $voice, 'language' => $language,
            'transcription_model' => config('voice.native.transcription_model'),
            'instructions' => $agent->instructions, 'greeting' => $agent->greeting ?? '',
            'personality' => $agent->personality,
        ];
    }
}
