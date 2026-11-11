<?php

namespace App\Services\Providers;

use App\Models\Organization;
use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\Data\ResolvedProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Gate;

final class ProviderManager
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function resolve(Organization $organization, Capability $capability, User $actor, ?string $provider = null): ResolvedProvider
    {
        // Authorize before even querying routing or decrypting shared/local credentials.
        Gate::forUser($actor)->authorize('view', $organization);

        if ($provider !== null) {
            $local = ProviderConfiguration::query()->forScope($organization)->where('capability', $capability->value)->where('provider', $provider)->first();
            if ($resolved = $this->resolveConfiguration($local, $capability)) {
                return $resolved;
            }
            $platform = ProviderConfiguration::query()->forScope(null)->where('capability', $capability->value)->where('provider', $provider)->first();
            if ($resolved = $this->resolveConfiguration($platform, $capability)) {
                return $resolved;
            }
            throw new ProviderUnavailable("No enabled, freshly verified configuration is available for {$provider} {$capability->label()}.");
        }

        $primary = ProviderConfiguration::query()->forScope($organization)->where('capability', $capability->value)->where('routing_role', 'default')->first();
        // A local default explicitly overrides platform routing, even if it later fails.
        $scope = $primary ? $organization : null;
        $primary ??= ProviderConfiguration::query()->forScope(null)->where('capability', $capability->value)->where('routing_role', 'default')->first();
        if ($resolved = $this->resolveConfiguration($primary, $capability)) {
            return $resolved;
        }
        $fallback = ProviderConfiguration::query()->forScope($scope)->where('capability', $capability->value)->where('routing_role', 'fallback')->first();
        if ($resolved = $this->resolveConfiguration($fallback, $capability)) {
            return $resolved;
        }
        throw new ProviderUnavailable('No enabled, freshly verified default or compatible explicit fallback is configured for '.$capability->label().'.');
    }

    public function resolveNativeRealtime(Organization $organization, User $actor, ?string $provider = null): ResolvedProvider
    {
        return $this->resolve($organization, Capability::Realtime, $actor, $provider);
    }

    /** Native realtime is deliberately NOT represented as this pipeline. */
    public function resolveModularPipeline(Organization $organization, User $actor): array
    {
        return [
            'stt' => $this->resolve($organization, Capability::SpeechToText, $actor),
            'llm' => $this->resolve($organization, Capability::LanguageModel, $actor),
            'tts' => $this->resolve($organization, Capability::TextToSpeech, $actor),
        ];
    }

    private function resolveConfiguration(?ProviderConfiguration $configuration, Capability $capability): ?ResolvedProvider
    {
        if (! $configuration || $configuration->capability !== $capability->value || ! $this->registry->supports($configuration->provider, $capability) || ! $configuration->readyForResolution()) {
            return null;
        }
        try {
            $credentials = new ProviderCredentials($configuration->api_key, $configuration->project_id, $configuration->provider_organization);
        } catch (DecryptException) {
            return null;
        }

        return new ResolvedProvider($configuration->id, $configuration->provider, $capability, $configuration->selected_model, $configuration->selected_voice, $credentials, $this->registry->adapter($configuration->provider, $capability));
    }
}
