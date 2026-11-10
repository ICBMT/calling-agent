<?php

namespace Tests\Concerns;

use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Providers\ProviderConnectionTester;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/** Protocol doubles only. These values must never be seeded in a real workspace. */
trait VoiceFixtures
{
    protected array $dispatches = [];

    protected bool $failCleanup = false;

    protected bool $failDispatch = false;

    protected mixed $onDispatch = null;

    protected string $fixtureProviderKey = 'test-only-voice-key-not-a-real-provider-credential';

    protected function configureVoice(): void
    {
        config([
            'voice.enabled' => true, 'voice.livekit_url' => 'wss://rtc.example.test',
            'voice.livekit_api_key' => 'test-only-livekit-key',
            'voice.livekit_api_secret' => 'test-only-livekit-secret-'.str_repeat('s', 32),
            'voice.worker_secret' => 'test-only-worker-secret-'.str_repeat('w', 32),
            'voice.worker_key_id' => 'v1',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.openai.com/v1/models') {
                return Http::response(['data' => [['id' => 'gpt-realtime-2.1'], ['id' => 'gpt-realtime-2.1-mini'], ['id' => 'gpt-transcribe'], ['id' => 'not-a-supported-model']]]);
            }
            if ($request->url() === 'https://rtc.example.test/twirp/livekit.RoomService/CreateRoom') {
                return Http::response(['name' => $request['name'], 'sid' => 'RM_test']);
            }
            if ($request->url() === 'https://rtc.example.test/twirp/livekit.AgentDispatchService/CreateDispatch') {
                $this->dispatches[] = json_decode($request['metadata'], true, flags: JSON_THROW_ON_ERROR);
                if ($this->onDispatch) {
                    ($this->onDispatch)(end($this->dispatches));
                }

                return $this->failDispatch ? Http::response(['code' => 'unavailable', 'msg' => $this->fixtureProviderKey], 503) : Http::response(['id' => 'AD_test'.count($this->dispatches)]);
            }
            if ($request->url() === 'https://rtc.example.test/twirp/livekit.RoomService/DeleteRoom') {
                return $this->failCleanup ? Http::response(['code' => 'unavailable', 'msg' => $this->fixtureProviderKey], 503) : Http::response('{}', 200, ['Content-Type' => 'application/json']);
            }
            throw new \RuntimeException('Unexpected protocol fixture request.');
        });
    }

    protected function readyVoiceProvider(User $owner, bool $platform = false): ProviderConfiguration
    {
        if ($platform) {
            $owner->forceFill(['is_platform_admin' => true])->save();
        }
        $store = app(ProviderConfigurationStore::class);
        $record = $store->create($platform ? null : $owner->currentOrganization, $owner, [
            'name' => 'Test-only Realtime', 'provider' => 'openai', 'capability' => 'realtime',
            'api_key' => $this->fixtureProviderKey, 'enabled' => true,
        ])->fresh();
        app(ProviderConnectionTester::class)->test($record, $owner, $record->revision);
        $record->refresh();
        $store->selection($record, $owner, ['revision' => $record->revision, 'selected_model' => 'gpt-realtime-2.1', 'selected_voice' => 'marin', 'routing_role' => 'default']);

        return $record->fresh();
    }

    protected function voiceData(User $actor, array $overrides = []): array
    {
        $agent = $actor->currentOrganization->agents()->first() ?? $actor->currentOrganization->agents()->create($this->agentData());

        return array_replace([
            'agent_id' => $agent->id, 'architecture' => 'native_realtime',
            'model' => 'gpt-realtime-2.1', 'voice' => 'marin', 'language' => 'en', 'consent' => true,
        ], $overrides);
    }

    protected function startVoice(User $actor): array
    {
        $response = $this->actingAs($actor)->postJson('/app/voice-sessions', $this->voiceData($actor))->assertCreated();

        return $response->json();
    }

    protected function ticketFor(string $id): array
    {
        return array_values(array_filter($this->dispatches, fn ($dispatch) => $dispatch['session_id'] === $id))[0];
    }

    protected function claimVoice(string $id): array
    {
        $metadata = $this->ticketFor($id);

        return $this->workerPost($id, 'claim', ['room' => $metadata['room'], 'ticket' => $metadata['ticket'], 'job_id' => 'AJ_test'])->assertOk()->json();
    }

    protected function pulseData(array $grant, int $sequence = 1, array $overrides = []): array
    {
        return array_replace([
            'room' => $grant['room'], 'job_id' => 'AJ_test', 'lease' => $grant['lease'],
            'sequence' => $sequence, 'state' => 'active', 'metrics' => ['turns' => 1, 'worker_turn_ms' => 456],
        ], $overrides);
    }

    protected function workerPost(string $id, string $operation, array $data, array $overrides = [], ?string $raw = null, string $query = ''): TestResponse
    {
        $path = '/internal/voice/sessions/'.$id.'/'.$operation;
        $body = $raw ?? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(16));
        $keyId = config('voice.worker_key_id');
        $canonical = implode("\n", ['v1', $keyId, 'POST', $path, $timestamp, $nonce, hash('sha256', $body)]);
        $headers = array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VOCA_KEY_ID' => $keyId, 'HTTP_X_VOCA_TIMESTAMP' => $timestamp,
            'HTTP_X_VOCA_NONCE' => $nonce,
            'HTTP_X_VOCA_SIGNATURE' => hash_hmac('sha256', $canonical, config('voice.worker_secret')),
        ], $overrides);

        return $this->call('POST', $path.$query, [], [], [], $headers, $body);
    }

    protected function decodeVoiceToken(string $token): array
    {
        [$header, $payload, $signature] = explode('.', $token);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($header, '-_', '+/')), true));
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, config('voice.livekit_api_secret'), true)), '+/', '-_'), '='), $signature);

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    }
}
