<?php

namespace Tests\Feature;

use App\Models\VoiceSession;
use App\Services\AuditLogger;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Voice\WorkerGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\VoiceFixtures;
use Tests\TestCase;

class VoiceWorkerSecurityTest extends TestCase
{
    use RefreshDatabase, VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureVoice();
    }

    public function test_browser_cookies_are_not_worker_authentication_and_credentials_are_never_released(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $metadata = $this->ticketFor($result['session']['id']);
        $response = $this->actingAs($owner)->postJson('/internal/voice/sessions/'.$metadata['session_id'].'/claim', ['room' => $metadata['room'], 'ticket' => $metadata['ticket'], 'job_id' => 'AJ_test']);
        $response->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($this->fixtureProviderKey)->assertJsonPath('code', 'invalid_worker_request');
        $this->assertNull(VoiceSession::query()->first()->claimed_at);
    }

    public static function badHmacHeaders(): array
    {
        return [
            'wrong key id' => [['HTTP_X_VOCA_KEY_ID' => 'wrong']],
            'old timestamp' => [['HTTP_X_VOCA_TIMESTAMP' => '1000000000']],
            'far future' => [['HTTP_X_VOCA_TIMESTAMP' => '9999999999']],
            'missing nonce' => [['HTTP_X_VOCA_NONCE' => '']],
            'bad signature' => [['HTTP_X_VOCA_SIGNATURE' => str_repeat('0', 64)]],
            'not JSON' => [['CONTENT_TYPE' => 'text/plain']],
        ];
    }

    #[DataProvider('badHmacHeaders')]
    public function test_hmac_checks_are_mandatory_before_session_lookup_or_plaintext_delivery(array $headers): void
    {
        config(['app.debug' => true]);
        $response = $this->workerPost('11111111-1111-4111-8111-111111111111', 'claim', ['ticket' => str_repeat('a', 64), 'room' => 'voca-11111111-1111-4111-8111-111111111111', 'job_id' => 'AJ_test'], $headers);
        $response->assertUnauthorized()->assertJsonPath('code', 'invalid_worker_request')->assertDontSee('trace')->assertDontSee(config('voice.worker_secret'));
        $this->assertDatabaseCount('voice_worker_nonces', 0);
    }

    public function test_exact_body_path_method_timestamp_and_nonce_are_signed_and_nonce_replays_are_durable(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $path = '/internal/voice/sessions/'.$id.'/claim';
        $timestamp = (string) now()->timestamp;
        $nonce = str_repeat('a', 32);
        $body = json_encode(['ticket' => str_repeat('b', 64), 'room' => 'voca-'.$id, 'job_id' => 'AJ_test'], JSON_UNESCAPED_SLASHES);
        $canonical = implode("\n", ['v1', 'v1', 'POST', $path, $timestamp, $nonce, hash('sha256', $body)]);
        $headers = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VOCA_KEY_ID' => 'v1', 'HTTP_X_VOCA_TIMESTAMP' => $timestamp, 'HTTP_X_VOCA_NONCE' => $nonce,
            'HTTP_X_VOCA_SIGNATURE' => hash_hmac('sha256', $canonical, config('voice.worker_secret')),
        ];
        $this->call('POST', $path, [], [], [], $headers, $body)->assertNotFound(); // Authenticated but no session exists.
        $this->assertDatabaseCount('voice_worker_nonces', 1);
        $this->call('POST', $path, [], [], [], $headers, $body)->assertUnauthorized();
        $this->call('POST', $path, [], [], [], $headers, $body.' ')->assertUnauthorized();
        $this->call('POST', str_replace('/claim', '/heartbeat', $path), [], [], [], $headers, $body)->assertUnauthorized();
        $this->call('POST', $path.'?extra=1', [], [], [], $headers, $body)->assertUnauthorized();
        $this->assertDatabaseCount('voice_worker_nonces', 1);
        $this->travel(121)->seconds();
        $this->artisan('voice:reap')->assertSuccessful();
        $this->assertDatabaseCount('voice_worker_nonces', 0);
    }

    public function test_oversized_signed_body_is_rejected_before_replay_or_session_storage(): void
    {
        $this->workerPost('11111111-1111-4111-8111-111111111111', 'claim', [], raw: str_repeat('x', 8193))->assertUnauthorized();
        $this->assertDatabaseCount('voice_worker_nonces', 0);
    }

    public function test_claim_is_one_use_and_bound_to_the_generated_room_and_expected_job(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $metadata = $this->ticketFor($result['session']['id']);
        $data = ['room' => $metadata['room'], 'ticket' => str_repeat('0', 64), 'job_id' => 'AJ_test'];
        $this->workerPost($metadata['session_id'], 'claim', $data)->assertUnauthorized();
        $data['ticket'] = $metadata['ticket'];
        $data['room'] = 'voca-11111111-1111-4111-8111-111111111111';
        $this->workerPost($metadata['session_id'], 'claim', $data)->assertUnauthorized();
        $grant = $this->claimVoice($metadata['session_id']);
        $this->assertSame($this->fixtureProviderKey, $grant['credentials']['api_key']);
        $this->assertSame($result['connection']['agent_identity'], $grant['worker_identity']);
        $this->assertSame($result['connection']['identity'], $grant['participant_identity']);
        $this->assertSame(10, $grant['heartbeat_seconds']);
        $this->assertSame(25, $grant['lease_seconds']);
        $this->assertNull(VoiceSession::query()->first()->claim_hash);
        $this->assertSame(hash('sha256', $grant['lease']), VoiceSession::query()->first()->lease_hash);
        $data['room'] = $metadata['room'];
        $this->workerPost($metadata['session_id'], 'claim', $data)->assertUnauthorized()->assertDontSee($this->fixtureProviderKey);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, overrides: ['job_id' => 'AJ_other']))->assertUnauthorized();
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, overrides: ['lease' => str_repeat('0', 64)]))->assertUnauthorized();
        $this->assertSame('starting', VoiceSession::query()->first()->state);
    }

    public static function revocations(): array
    {
        return array_map(fn ($kind) => [$kind], ['membership', 'downgrade', 'actor_deleted', 'agent_deleted', 'provider_deleted', 'disabled', 'key_removed', 'rotated', 'revision_changed', 'catalog_expired', 'model_retired']);
    }

    #[DataProvider('revocations')]
    public function test_revocation_between_start_and_claim_never_releases_old_or_new_credentials(string $kind): void
    {
        $owner = $this->workspaceUser();
        $provider = $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $session = VoiceSession::query()->find($result['session']['id']);
        match ($kind) {
            'membership' => $owner->currentOrganization->users()->detach($owner),
            'downgrade' => $owner->currentOrganization->users()->updateExistingPivot($owner->id, ['role' => 'member']),
            'actor_deleted' => $owner->delete(),
            'agent_deleted' => $owner->currentOrganization->agents()->first()->delete(),
            'provider_deleted' => app(ProviderConfigurationStore::class)->delete($provider, $owner, $provider->revision),
            'disabled' => $provider->forceFill(['enabled' => false])->save(),
            'key_removed' => app(ProviderConfigurationStore::class)->removeCredential($provider, $owner, $provider->revision),
            'rotated' => app(ProviderConfigurationStore::class)->update($provider, $owner, ['revision' => $provider->revision, 'name' => $provider->name, 'enabled' => true, 'api_key' => 'test-only-rotated-new-key']),
            'revision_changed' => $provider->increment('revision'),
            'catalog_expired' => $provider->forceFill(['last_tested_at' => now()->subHours(25)])->save(),
            'model_retired' => config(['voice.native.models' => ['gpt-realtime-2.1-mini']]),
        };
        $metadata = $this->ticketFor($session->id);
        $this->workerPost($session->id, 'claim', ['room' => $metadata['room'], 'ticket' => $metadata['ticket'], 'job_id' => 'AJ_test'])->assertStatus(409)->assertJsonPath('code', 'access_revoked')->assertDontSee($this->fixtureProviderKey)->assertDontSee('rotated-new-key')->assertJsonMissingPath('credentials');
        $this->assertSame('revoked', $session->fresh()->state);
        $this->assertNull($session->fresh()->configuration_snapshot);
        $this->assertNull($session->fresh()->lease_hash);
    }

    public function test_a_changed_m2_routing_decision_does_not_silently_fail_over_mid_session(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        $this->readyVoiceProvider($owner); // The real M2 routing service moves the unique default.
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant))->assertOk()->assertJsonPath('continue', false)->assertJsonPath('error_code', 'access_revoked');
        $this->assertNull(VoiceSession::query()->first()->lease_hash);
    }

    public function test_revoking_a_live_membership_ends_the_lease_and_out_of_order_events_cannot_revive_it(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant))->assertOk()->assertJsonPath('continue', true)->assertJsonPath('state', 'active');
        $owner->currentOrganization->users()->updateExistingPivot($owner->id, ['role' => 'member']);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, 2))->assertOk()->assertJsonPath('continue', false)->assertJsonPath('state', 'revoked');
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, 3))->assertUnauthorized();
        $finish = $this->pulseData($grant, 4);
        unset($finish['state']);
        $this->workerPost($grant['session_id'], 'finish', $finish)->assertUnauthorized();
        $this->assertSame('revoked', VoiceSession::query()->first()->state);
    }

    public function test_the_worker_cannot_renew_an_expired_heartbeat_lease_or_hard_deadline(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        $this->travel(26)->seconds();
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant))->assertOk()->assertJsonPath('continue', false)->assertJsonPath('error_code', 'worker_timeout');
        $this->assertSame('failed', VoiceSession::query()->first()->state);
    }

    public function test_event_sequences_are_monotonic_and_active_state_cannot_downgrade(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, 2))->assertOk();
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, 1))->assertStatus(409);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, 2))->assertStatus(409);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, 3, ['state' => 'starting']))->assertStatus(409);
        $this->assertSame(2, VoiceSession::query()->first()->event_sequence);
        $this->assertSame('active', VoiceSession::query()->first()->state);
    }

    public static function badMetrics(): array
    {
        return [
            'raw transcript' => [['transcript' => 'Do not persist conversation content']],
            'credential' => [['api_key' => 'Never reflect a key']],
            'string duration' => [['worker_turn_ms' => '123']],
            'negative' => [['worker_turn_ms' => -1]],
            'huge' => [['worker_turn_ms' => 120001]],
            'nested' => [['turns' => ['private' => 1]]],
        ];
    }

    #[DataProvider('badMetrics')]
    public function test_only_bounded_numeric_metrics_are_persisted_or_returned(array $metrics): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant, overrides: ['metrics' => $metrics]))->assertStatus(422)->assertDontSee('Do not persist')->assertDontSee('Never reflect');
        $this->assertNull(VoiceSession::query()->first()->metrics);
    }

    public function test_finish_persists_actual_allowlisted_numbers_without_claiming_audio_acceptance_and_destroys_secrets(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        $data = $this->pulseData($grant);
        unset($data['state']);
        $data['error_code'] = 'provider_auth';
        $this->workerPost($grant['session_id'], 'finish', $data)->assertOk()->assertJsonPath('ended', true)->assertJsonPath('cleanup_pending', false);
        $session = VoiceSession::query()->first();
        $this->assertSame('failed', $session->state);
        $this->assertSame(['turns' => 1, 'worker_turn_ms' => 456], $session->metrics);
        $this->assertNull($session->configuration_snapshot);
        $this->assertNull($session->lease_hash);
        $this->actingAs($owner)->getJson('/app/voice-sessions/'.$session->id)->assertOk()->assertJsonPath('session.error_code', 'provider_auth')->assertJsonMissingPath('credentials')->assertJsonMissingPath('connection')->assertDontSee($grant['lease'])->assertDontSee($this->fixtureProviderKey);
        $this->assertDatabaseCount('calls', 0);
    }

    public function test_worker_grant_debug_json_and_serialization_cannot_accidentally_expose_credentials(): void
    {
        $grant = new WorkerGrant(['credentials' => ['api_key' => $this->fixtureProviderKey], 'lease' => str_repeat('a', 64)]);
        $this->assertStringNotContainsString($this->fixtureProviderKey, json_encode($grant));
        ob_start();
        var_dump($grant);
        $this->assertStringNotContainsString($this->fixtureProviderKey, ob_get_clean());
        $this->expectException(\LogicException::class);
        serialize($grant);
    }

    public function test_unexpected_voice_errors_are_generic_and_class_only_logged_even_with_debug_enabled(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        config(['app.debug' => true]);
        $logPath = storage_path('logs/voice-boundary-'.bin2hex(random_bytes(6)).'.log');
        config(['logging.default' => 'voice_review', 'logging.channels.voice_review' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);
        $secret = $this->fixtureProviderKey;
        $this->mock(AuditLogger::class, function ($mock) use ($secret): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Raw '.$secret, 0, new RuntimeException('Previous '.$secret)));
        });
        try {
            $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner))->assertStatus(500)->assertDontSee($secret)->assertDontSee('trace')->assertDontSee('Raw ')->assertHeader('Cache-Control', 'no-store, private');
            Log::forgetChannel('voice_review');
            $log = file_get_contents($logPath);
            $this->assertStringContainsString('Voice operation failed.', $log);
            $this->assertStringContainsString('RuntimeException', $log);
            foreach ([$secret, 'Raw ', 'Previous ', '[stacktrace]', 'api_key'] as $value) {
                $this->assertStringNotContainsString($value, $log);
            }
        } finally {
            Log::forgetChannel('voice_review');
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }
}
