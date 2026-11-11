<?php

namespace App\Services\Providers;

use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;

final class Catalog
{
    public function __construct(#[\SensitiveParameter] private readonly ProviderCredentials $credentials) {}

    public function id(mixed $value): ?string
    {
        if (! is_string($value) || $value !== $this->credentials->redact($value) || ! preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9_.:/-]{0,199}\z~D', $value)) {
            return null;
        }

        return $value;
    }

    public function text(mixed $value, string $fallback = ''): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $this->credentials->redact($value)) ?? '', 0, 160);
    }

    public function languages(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter(array_map(fn ($value) => is_string($value) && preg_match('/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/D', $value) ? $value : null, array_slice($values, 0, 200)))) : [];
    }

    public function discovery(#[\SensitiveParameter] array $models, #[\SensitiveParameter] array $voices = [], #[\SensitiveParameter] array $usage = [], #[\SensitiveParameter] array $warnings = []): Discovery
    {
        $this->credentials->assertSafeMetadata([$models, $voices, $usage, $warnings]);

        return new Discovery($models, $voices, $usage, $warnings);
    }

    public static function expired(?string $date): bool
    {
        return $date !== null && (! self::validDate($date) || strcmp($date, now()->toDateString()) <= 0);
    }

    private static function validDate(string $date): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** Validate the provider's RFC 3339 timestamp before any permissive date parser sees it. */
    public static function validTimestamp(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,9})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/D', $value)
            && self::validDate(substr($value, 0, 10));
    }

    public function documented(string $provider, string $id, string $name, Capability $capability, ?string $shutdown = null): ?array
    {
        $entry = config("providers.documented_models.$provider")[$id] ?? null;
        if (! $entry || ($entry['capability'] ?? null) !== $capability->value) {
            return null;
        }
        $date = $shutdown;
        if (isset($entry['shutdown_date']) && ($date === null || strcmp($entry['shutdown_date'], $date) < 0)) {
            $date = $entry['shutdown_date'];
        }
        if ($date !== null && (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) || self::expired($date))) {
            return null;
        }

        return [
            'id' => $id, 'name' => $this->text($name, $id), 'capability' => $capability->value,
            'api' => $entry['api'], 'streaming' => $entry['streaming'] ?? null,
            'languages' => [], 'voice_ids' => $entry['voices'] ?? [],
            'shutdown_date' => $date, 'custom_voices' => $entry['custom_voices'] ?? false,
            'source' => 'Authenticated model list + official capability map ('.config('providers.reviewed_on').')',
        ];
    }

    public static function selectedModel(array $catalog, ?string $model): ?array
    {
        if ($model === null || $model === '') {
            return null;
        }
        $models = $catalog['models'] ?? null;
        if (! is_array($models) || ! array_is_list($models)) {
            return null;
        }
        foreach ($models as $entry) {
            if (! is_array($entry) || ($entry['id'] ?? null) !== $model) {
                continue;
            }
            $shutdown = $entry['shutdown_date'] ?? null;
            if ($shutdown !== null && ! is_string($shutdown)) {
                continue;
            }
            if (! self::expired($shutdown)) {
                return $entry;
            }
        }

        return null;
    }

    public static function validSelection(array $catalog, Capability $capability, ?string $model, ?string $voice, string $provider): bool
    {
        $entry = self::selectedModel($catalog, $model);
        $definitions = config('providers.definitions');
        if (! $entry || ($entry['capability'] ?? null) !== $capability->value
            || ! in_array($capability->value, $definitions[$provider]['capabilities'] ?? [], true)) {
            return false;
        }
        // An existing snapshot cannot grandfather a removed/reclassified documented model or voice.
        $documented = null;
        if (in_array($provider, ['openai', 'gemini'], true)) {
            $documented = config("providers.documented_models.$provider", [])[$model] ?? null;
            if (! is_array($documented) || ($documented['capability'] ?? null) !== $capability->value
                || self::expired($documented['shutdown_date'] ?? null)) {
                return false;
            }
            if ($provider === 'openai' && $capability->needsVoice() && ! in_array($voice, $documented['voices'] ?? [], true)) {
                return false;
            }
        }
        if (! $capability->needsVoice()) {
            return $voice === null || $voice === '';
        }
        if ($voice === null || $voice === '') {
            return false;
        }
        $voiceIds = $entry['voice_ids'] ?? null;
        if (! is_array($voiceIds) || ! array_is_list($voiceIds) || ! in_array($voice, $voiceIds, true)) {
            return false;
        }
        $voices = $catalog['voices'] ?? null;
        if (! is_array($voices) || ! array_is_list($voices)) {
            return false;
        }
        foreach ($voices as $item) {
            if (! is_array($item) || ($item['id'] ?? null) !== $voice) {
                continue;
            }
            if ($provider === 'gemini' && ! ($documented['custom_voices'] ?? false) && ($item['type'] ?? null) !== 'prebuilt') {
                continue;
            }
            $expiry = $item['expires_at'] ?? null;
            // Stored expiry is normalized to a full UTC second before persistence.
            if ($expiry === null || (self::validTimestamp($expiry) && strlen($expiry) === 20 && str_ends_with($expiry, 'Z') && strcmp($expiry, now()->toIso8601ZuluString()) > 0)) {
                return true;
            }
        }

        return false;
    }
}
