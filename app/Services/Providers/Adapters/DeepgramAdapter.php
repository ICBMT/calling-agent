<?php

namespace App\Services\Providers\Adapters;

use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\ProviderFailure;
use App\Services\Providers\ProviderHttpClient;

final class DeepgramAdapter implements ProviderAdapter
{
    public function __construct(private readonly ProviderHttpClient $http) {}

    public function discover(#[\SensitiveParameter] ProviderCredentials $credentials, Capability $capability): Discovery
    {
        if (! in_array($capability, [Capability::SpeechToText, Capability::TextToSpeech], true)) {
            throw new ProviderFailure('unexpected_response');
        }
        $deadline = microtime(true) + config('providers.test_budget_seconds');

        // Step 1: Validate credentials using official key validation endpoint (/v1/auth/token)
        $token = $this->http->get('deepgram', '/v1/auth/token', $credentials, deadline: $deadline);
        if (! is_array($token) || empty($token['api_key_id'])) {
            throw new ProviderFailure('unexpected_response', 200);
        }

        // Step 2: Discover models through official models endpoint (/v1/models)
        $data = $this->http->get('deepgram', '/v1/models', $credentials, deadline: $deadline);
        $normalizer = new Catalog($credentials);
        $models = $voices = [];
        foreach (ProviderHttpClient::list($data, $capability->value) as $entry) {
            $id = $normalizer->id($entry['canonical_name'] ?? null);
            if (! $id) {
                continue;
            }
            $name = $normalizer->text($entry['name'] ?? null, $id);
            $models[$id] = [
                'id' => $id, 'name' => $name, 'capability' => $capability->value,
                'api' => $capability === Capability::SpeechToText ? 'Listen' : 'Speak',
                'languages' => $normalizer->languages($entry['languages'] ?? []),
                'streaming' => is_bool($entry['streaming'] ?? null) ? $entry['streaming'] : null,
                'voice_ids' => $capability->needsVoice() ? [$id] : [],
                'source' => 'Authenticated Deepgram models API',
            ];
            if ($capability->needsVoice()) {
                $voices[$id] = ['id' => $id, 'name' => $name, 'source' => 'Aura model identifies its voice'];
            }
        }

        return $normalizer->discovery(array_values($models), array_values($voices));
    }
}
