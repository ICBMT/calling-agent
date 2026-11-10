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
        $path = '/v1/projects/'.$credentials->projectId;
        $project = $this->http->get('deepgram', $path, $credentials, deadline: $deadline);
        if (($project['project_id'] ?? null) !== $credentials->projectId) {
            throw new ProviderFailure('unexpected_response', 200);
        }
        $data = $this->http->get('deepgram', $path.'/models', $credentials, ['include_outdated' => 'false'], $deadline);
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
                'source' => 'Authenticated project model metadata',
            ];
            if ($capability->needsVoice()) {
                $voices[$id] = ['id' => $id, 'name' => $name, 'source' => 'Aura model identifies its voice'];
            }
        }
        $usage = $warnings = [];
        try {
            $balances = $this->http->get('deepgram', $path.'/balances', $credentials, deadline: $deadline);
            foreach (ProviderHttpClient::list($balances, 'balances', 100) as $balance) {
                if (is_numeric($balance['amount'] ?? null) && is_finite((float) $balance['amount']) && abs((float) $balance['amount']) < 1.0e12 && is_string($balance['units'] ?? null) && preg_match('/\A[A-Z]{3}\z/D', $balance['units'])) {
                    $usage[] = ['label' => 'Project balance ('.$balance['units'].')', 'value' => (string) round((float) $balance['amount'], 4)];
                }
            }
        } catch (ProviderFailure $failure) {
            $warnings[] = 'Optional project balance is unavailable ('.$failure->reason.($failure->httpStatus ? ', HTTP '.$failure->httpStatus : '').'). Authentication/catalog results are independent.';
        }

        return $normalizer->discovery(array_values($models), array_values($voices), $usage, $warnings);
    }
}
