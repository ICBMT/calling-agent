<?php

namespace App\Services\Voice;

use App\Models\VoiceSession;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Fixed Twirp methods against the operator-configured origin. Never an open URL proxy. */
final class LiveKitGateway
{
    private const MAX_BYTES = 65536;

    public function __construct(private readonly VoiceInfrastructure $infrastructure, private readonly LiveKitTokens $tokens) {}

    public function createRoom(VoiceSession $session): void
    {
        $data = $this->request('RoomService', 'CreateRoom', [
            'name' => $session->room_name, 'empty_timeout' => 60,
            'departure_timeout' => 5, 'max_participants' => 2,
        ], ['roomCreate' => true]);
        if (($data['name'] ?? null) !== $session->room_name) {
            throw new VoiceFailure('livekit_unavailable', 503);
        }
    }

    public function dispatch(VoiceSession $session, #[\SensitiveParameter] string $ticket): string
    {
        $data = $this->request('AgentDispatchService', 'CreateDispatch', [
            'room' => $session->room_name, 'agent_name' => config('voice.worker_name'),
            // No instructions, provider credentials, user names or email addresses in job metadata.
            'metadata' => json_encode(['v' => 1, 'session_id' => $session->id, 'room' => $session->room_name, 'ticket' => $ticket], JSON_THROW_ON_ERROR),
        ], ['roomAdmin' => true, 'room' => $session->room_name]);
        if (! is_string($data['id'] ?? null) || ! preg_match('/\AAD_[a-zA-Z0-9_-]{1,96}\z/D', $data['id'])) {
            throw new VoiceFailure('livekit_unavailable', 503);
        }

        return $data['id'];
    }

    public function deleteRoom(VoiceSession $session): void
    {
        $this->request('RoomService', 'DeleteRoom', ['room' => $session->room_name], ['roomCreate' => true], allowMissing: true);
    }

    private function request(string $service, string $method, #[\SensitiveParameter] array $body, array $grants, bool $allowMissing = false): array
    {
        $this->infrastructure->requireLiveKit();
        $origin = preg_replace('/\Awss:/', 'https:', $this->infrastructure->url());
        $origin = preg_replace('/\Aws:/', 'http:', $origin);
        try {
            $response = Http::acceptJson()->withToken($this->tokens->service($grants))
                ->connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions([
                    'verify' => true, 'cookies' => false,
                    'on_headers' => function ($response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new VoiceFailure('livekit_unavailable', 503);
                        }
                    },
                    'progress' => function ($total, $downloaded): void {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new VoiceFailure('livekit_unavailable', 503);
                        }
                    },
                ])->post($origin.'/twirp/livekit.'.$service.'/'.$method, $body);
            if (strlen($response->body()) > self::MAX_BYTES) {
                throw new VoiceFailure('livekit_unavailable', 503);
            }
            $data = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new VoiceFailure('livekit_unavailable', 503);
            }
            if ($allowMissing && $response->status() === 404 && ($data['code'] ?? null) === 'not_found') {
                return [];
            }
            if ($response->status() !== 200 || isset($data['code'])) {
                throw new VoiceFailure('livekit_unavailable', 503);
            }

            return $data;
        } catch (Throwable) {
            // Do not retain upstream bodies, request headers or wrapped exception chains.
            throw new VoiceFailure('livekit_unavailable', 503);
        }
    }
}
