<?php

namespace App\Services\Providers\Adapters;

use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\ProviderFailure;
use App\Services\Providers\ProviderHttpClient;

final class ElevenLabsAdapter implements ProviderAdapter
{
    public function __construct(private readonly ProviderHttpClient $http) {}

    public function discover(#[\SensitiveParameter] ProviderCredentials $credentials, Capability $capability): Discovery
    {
        if ($capability !== Capability::TextToSpeech) {
            throw new ProviderFailure('unexpected_response');
        }
        $deadline = microtime(true) + config('providers.test_budget_seconds');
        $subscription = $this->http->get('elevenlabs', '/v1/user/subscription', $credentials, deadline: $deadline);
        if (! is_string($subscription['tier'] ?? null) || ! is_int($subscription['character_count'] ?? null) || ! is_int($subscription['character_limit'] ?? null) || $subscription['character_count'] < 0 || $subscription['character_limit'] < 0) {
            throw new ProviderFailure('unexpected_response', 200);
        }
        $normalizer = new Catalog($credentials);
        $data = $this->http->get('elevenlabs', '/v1/models', $credentials, deadline: $deadline);
        $models = $rawModels = $voices = [];
        foreach (ProviderHttpClient::list(['models' => $data], 'models', 200) as $entry) {
            $id = $normalizer->id($entry['model_id'] ?? null);
            if (! $id || ($entry['can_do_text_to_speech'] ?? false) !== true || (array_key_exists('requires_alpha_access', $entry) && $entry['requires_alpha_access'] !== false)) {
                continue;
            }
            $rawModels[$id] = $entry;
            $models[$id] = [
                'id' => $id, 'name' => $normalizer->text($entry['name'] ?? null, $id), 'capability' => 'tts',
                'api' => 'Text to speech', 'streaming' => null, 'voice_ids' => [],
                'languages' => $normalizer->languages(array_column(is_array($entry['languages'] ?? null) ? $entry['languages'] : [], 'language_id')),
                'source' => 'Provider can_do_text_to_speech metadata + account voices',
            ];
        }
        foreach ($this->voices($credentials, $deadline) as $voice) {
            $id = $normalizer->id($voice['voice_id'] ?? null);
            if (! $id || ! in_array($voice['safety_control'] ?? null, [null, 'NONE'], true)
                || (array_key_exists('category', $voice) && ! in_array($voice['category'], ['generated', 'cloned', 'premade', 'professional', 'famous', 'high_quality'], true))) {
                continue;
            }
            $tiers = array_key_exists('available_for_tiers', $voice) ? $voice['available_for_tiers'] : [];
            if (! is_array($tiers) || ! array_is_list($tiers) || count(array_filter($tiers, 'is_string')) !== count($tiers)
                || (count($tiers) && ! in_array($subscription['tier'], $tiers, true))) {
                continue;
            }
            $compatible = [];
            foreach ($models as $modelId => $model) {
                if (($voice['category'] ?? '') === 'professional' && (($rawModels[$modelId]['serves_pro_voices'] ?? false) !== true || ($voice['fine_tuning']['state'][$modelId] ?? null) !== 'fine_tuned')) {
                    continue;
                }
                $qualityModels = $voice['high_quality_base_model_ids'] ?? [];
                if (($voice['category'] ?? '') === 'high_quality'
                    && (! is_array($qualityModels) || ! array_is_list($qualityModels) || ! in_array($modelId, $qualityModels, true))) {
                    continue;
                }
                $compatible[] = $modelId;
            }
            if (count($compatible)) {
                $voices[$id] = ['id' => $id, 'name' => $normalizer->text($voice['name'] ?? null, $id), 'source' => 'Authenticated account voice and tier/model metadata'];
                foreach ($compatible as $modelId) {
                    $models[$modelId]['voice_ids'][] = $id;
                }
            }
        }

        return $normalizer->discovery(array_values($models), array_values($voices), [
            ['label' => 'Characters used (provider account)', 'value' => (string) $subscription['character_count']],
            ['label' => 'Character allowance (provider account)', 'value' => (string) $subscription['character_limit']],
            ['label' => 'Subscription tier', 'value' => $normalizer->text($subscription['tier'])],
        ], ['Account catalog/tier checks do not prove synthesis entitlement or voice quality. Alpha models and incompatible/restricted voices are excluded.']);
    }

    private function voices(#[\SensitiveParameter] ProviderCredentials $credentials, float $deadline): array
    {
        $items = $seen = [];
        $token = null;
        for ($page = 0; $page < config('providers.max_pages'); $page++) {
            $query = ['page_size' => 100, 'include_total_count' => 'false'];
            if ($token) {
                $query['next_page_token'] = $token;
            }
            $data = $this->http->get('elevenlabs', '/v2/voices', $credentials, $query, $deadline);
            $items = array_merge($items, ProviderHttpClient::list($data, 'voices'));
            if (count($items) > 1000 || ! is_bool($data['has_more'] ?? null)) {
                throw new ProviderFailure('catalog_limit', 200);
            }
            if (! $data['has_more']) {
                return $items;
            }
            $token = $data['next_page_token'] ?? null;
            if (! is_string($token) || $token === '' || strlen($token) > 2048 || in_array($token, $seen, true)) {
                throw new ProviderFailure('catalog_limit', 200);
            }
            $seen[] = $token;
        }
        throw new ProviderFailure('catalog_limit', 200);
    }
}
