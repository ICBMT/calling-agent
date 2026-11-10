<?php

namespace App\Services\Voice;

final class VoiceFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct(self::messageFor($reason));
    }

    public static function messageFor(?string $reason): string
    {
        return match ($reason) {
            'not_configured' => 'Browser voice needs configured LiveKit infrastructure and the separate worker. Ask the installation operator to complete setup.',
            'provider_not_ready' => 'Configure, test and select a native realtime default in Providers. Catalog checks must be current; inference permissions and billing are also required.',
            'unsupported_provider' => 'This first browser voice slice supports OpenAI Realtime only. Other media providers and the modular pipeline are not connected yet.',
            'invalid_selection' => 'Choose a currently discovered, supported realtime model, voice and language. Recheck the provider catalog if the saved choice has disappeared.',
            'session_limit' => 'A browser session is already open for you, or the workspace concurrency limit has been reached. End it before starting another.',
            'livekit_unavailable' => 'LiveKit did not confirm the operation. Check its URL, credentials, network and worker deployment; no connection success is assumed.',
            'session_expired' => 'The browser session reached its time limit. Start a new session to continue.',
            'worker_timeout' => 'The voice worker did not start or its authority heartbeat stopped. Check the worker service before trying again.',
            'access_revoked' => 'This session was stopped because workspace access, the saved agent or its provider configuration changed.',
            'session_ended' => 'This session has ended. Start a new session; an old session cannot be resumed.',
            'invalid_worker_request' => 'The worker request could not be authorized.',
            'provider_auth' => 'The audio provider rejected authentication or model access. Check the encrypted key, project permissions and billing in Providers.',
            'provider_quota' => 'The audio provider reported a quota or rate limit. Check its billing and limits before starting again.',
            'provider_timeout' => 'The audio provider did not respond in time. The session was stopped rather than replaying stale audio.',
            'provider_error' => 'The audio provider could not continue the session. Check model, transcription permissions and provider availability.',
            'participant_timeout' => 'The browser did not join the room in time. Start a new session when the microphone is ready.',
            'browser_disconnected' => 'The browser left or could not recover its audio connection. Start a new session to continue.',
            'invalid_request' => 'The voice request is invalid. Refresh the playground and check your selections.',
            default => 'The voice operation could not be completed. Retry or contact the installation operator.',
        };
    }
}
