<?php

namespace App\Services\Providers\Adapters;

use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\ProviderFailure;
use App\Services\Providers\ProviderHttpClient;

final class OpenAiAdapter implements ProviderAdapter
{
    public function __construct(private readonly ProviderHttpClient $http) {}

    public function discover(#[\SensitiveParameter] ProviderCredentials $credentials, Capability $capability): Discovery
    {
        $data = $this->http->get('openai', '/v1/models', $credentials);
        $normalizer = new Catalog($credentials);
        $models = $voices = [];
        foreach (ProviderHttpClient::list($data, 'data') as $entry) {
            $id = $normalizer->id($entry['id'] ?? null);
            if (! $id) {
                throw new ProviderFailure('unexpected_response', 200);
            }
            $shutdown = $entry['shutdown_date'] ?? null;
            if ($shutdown !== null && ! is_string($shutdown)) {
                continue;
            }
            $model = $normalizer->documented('openai', $id, $id, $capability, $shutdown);
            if ($model) {
                $models[$id] = $model;
                foreach ($model['voice_ids'] as $voice) {
                    $voices[$voice] = ['id' => $voice, 'name' => ucfirst($voice), 'source' => 'Official built-in voice documentation; not synthesis-tested'];
                }
            }
        }

        return $normalizer->discovery(array_values($models), array_values($voices), warnings: [
            'The models endpoint does not report modalities or per-voice entitlement. Only documented exact IDs are selectable; unknown models are excluded. Built-in speech and Realtime voice sets are kept distinct.',
            ...($capability === Capability::SpeechToText ? ['Legacy whisper-1 and gpt-4o transcription models are deprecated and scheduled to shut down on 2027-02-26. Deprecated IDs are excluded automatically on their shutdown date.'] : []),
            ...($capability === Capability::TextToSpeech ? ['OpenAI Speech API TTS models are deprecated and scheduled to shut down on 2027-01-06. Realtime models use a different API, not /audio/speech.'] : []),
        ]);
    }
}
