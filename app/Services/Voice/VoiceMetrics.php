<?php

namespace App\Services\Voice;

final class VoiceMetrics
{
    private const LIMITS = [
        'provider_first_audio_ms' => 120000, 'worker_turn_ms' => 120000,
        'input_tokens' => 100000000, 'output_tokens' => 100000000,
        'input_audio_tokens' => 100000000, 'output_audio_tokens' => 100000000,
        'turns' => 10000, 'interruptions' => 10000,
    ];

    public static function normalize(array $values): array
    {
        $safe = [];
        if (array_diff(array_keys($values), array_keys(self::LIMITS))) {
            throw new VoiceFailure('invalid_request', 422);
        }
        foreach ($values as $name => $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > self::LIMITS[$name]) {
                throw new VoiceFailure('invalid_request', 422);
            }
            $safe[$name] = (int) round($value);
        }

        return $safe;
    }
}
