<?php

namespace App\Services\Providers\Adapters;

use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\ProviderFailure;
use App\Services\Providers\ProviderHttpClient;
use Illuminate\Support\Carbon;

final class GeminiAdapter implements ProviderAdapter
{
    public function __construct(private readonly ProviderHttpClient $http) {}

    public function discover(#[\SensitiveParameter] ProviderCredentials $credentials, Capability $capability): Discovery
    {
        if (! in_array($capability, [Capability::Realtime, Capability::LanguageModel, Capability::TextToSpeech], true)) {
            throw new ProviderFailure('unexpected_response');
        }
        $deadline = microtime(true) + config('providers.test_budget_seconds');
        $normalizer = new Catalog($credentials);
        $models = $voices = [];
        foreach ($this->pages('/v1beta/models', 'models', $credentials, $deadline) as $entry) {
            $id = $normalizer->id(preg_replace('~^models/~', '', is_string($entry['name'] ?? null) ? $entry['name'] : ''));
            if (! $id) {
                continue;
            }
            if ($capability === Capability::Realtime) {
                $methods = is_array($entry['supportedGenerationMethods'] ?? null) ? $entry['supportedGenerationMethods'] : null;
                if ($methods !== null && ! in_array('bidiGenerateContent', $methods, true)) {
                    continue;
                }
            }
            $model = $normalizer->documented('gemini', $id, $normalizer->text($entry['displayName'] ?? null, $id), $capability);
            if ($model) {
                $models[$id] = $model;
            }
        }
        if ($capability->needsVoice()) {
            $extra = $capability === Capability::Realtime ? ['type' => 'prebuilt'] : [];
            foreach ($this->pages('/v1beta/voices', 'voices', $credentials, $deadline, $extra) as $voice) {
                $id = $normalizer->id($voice['id'] ?? null);
                $type = $voice['type'] ?? null;
                if (! $id || ! in_array($type, ['prebuilt', 'prompted', 'replicated'], true) || ($capability === Capability::Realtime && $type !== 'prebuilt')) {
                    continue;
                }
                $expiry = null;
                if (isset($voice['expire_time'])) {
                    if (! Catalog::validTimestamp($voice['expire_time'])) {
                        continue;
                    }
                    try {
                        $expiry = Carbon::parse($voice['expire_time'])->utc()->toIso8601ZuluString();
                        if ($expiry <= now()->toIso8601ZuluString()) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
                $voices[$id] = array_filter([
                    'id' => $id, 'name' => $normalizer->text($voice['display_name'] ?? null, $id),
                    'type' => $type, 'expires_at' => $expiry, 'source' => 'Authenticated Gemini Voices API',
                ], fn ($value) => $value !== null);
            }
            foreach ($models as &$model) {
                $model['voice_ids'] = array_values(array_keys(array_filter($voices, fn ($voice) => $model['custom_voices'] || $voice['type'] === 'prebuilt')));
            }
            unset($model);
        }

        return $normalizer->discovery(array_values($models), array_values($voices), warnings: [
            'Model-list methods alone do not identify output modalities. Returned IDs are intersected with the dated official capability map; unknown models are not guessed. Live is an upstream provider capability; Voca worker live audio transport is not yet implemented. Dedicated TTS is a separate synthesis API.',
        ]);
    }

    private function pages(string $path, string $key, #[\SensitiveParameter] ProviderCredentials $credentials, float $deadline, array $extra = []): array
    {
        $items = $seen = [];
        $token = null;
        $snake = $key === 'voices';
        $maxItems = 5000;
        for ($page = 0; $page < config('providers.max_pages'); $page++) {
            $query = array_merge($extra, [$snake ? 'page_size' : 'pageSize' => 1000]);
            if ($token) {
                $query[$snake ? 'page_token' : 'pageToken'] = $token;
            }
            $data = $this->http->get('gemini', $path, $credentials, $query, $deadline);
            $items = array_merge($items, ProviderHttpClient::list($data, $key));
            if (count($items) > $maxItems) {
                throw new ProviderFailure('catalog_limit', 200);
            }
            $token = $data[$snake ? 'next_page_token' : 'nextPageToken'] ?? null;
            if ($token === null || $token === '') {
                return $items;
            }
            if (! is_string($token) || strlen($token) > 2048 || in_array($token, $seen, true)) {
                throw new ProviderFailure('catalog_limit', 200);
            }
            $seen[] = $token;
        }
        throw new ProviderFailure('catalog_limit', 200);
    }
}
