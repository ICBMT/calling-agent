<?php

namespace App\Services\Voice;

use App\Models\VoiceSession;

/** Fixed HS256 token issuer only; not a general-purpose JWT parser. SDK compatibility is tested. */
final class LiveKitTokens
{
    public function __construct(private readonly VoiceInfrastructure $infrastructure) {}

    public function browser(VoiceSession $session): string
    {
        $this->infrastructure->requireConfigured();

        return $this->issue([
            'roomJoin' => true, 'room' => $session->room_name,
            'canPublish' => true, 'canPublishSources' => ['microphone'],
            'canSubscribe' => true, 'canPublishData' => true,
            'canUpdateOwnMetadata' => false, 'canManageAgentSession' => false,
        ], $session->participant_identity, min(60, max(15, (int) config('voice.token_seconds'))));
    }

    public function service(array $grants): string
    {
        return $this->issue($grants, null, 30);
    }

    private function issue(array $grants, ?string $identity, int $seconds): string
    {
        $this->infrastructure->requireLiveKit();
        $claims = array_filter([
            'iss' => config('voice.livekit_api_key'), 'sub' => $identity,
            'iat' => now()->timestamp, 'nbf' => now()->timestamp - 5,
            'exp' => now()->timestamp + $seconds, 'video' => $grants,
        ], fn ($value) => $value !== null);
        $body = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$this->encode($claims);

        return $body.'.'.self::base64url(hash_hmac('sha256', $body, config('voice.livekit_api_secret'), true));
    }

    private function encode(array $data): string
    {
        return self::base64url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function base64url(#[\SensitiveParameter] string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
