<?php

namespace Tests\Feature;

use App\Models\VoiceSession;
use App\Services\SystemHealth;
use App\Services\Voice\VoiceInfrastructure;
use App\Services\Voice\VoiceSelection;
use App\Services\Voice\VoiceSessions;
use App\Services\Voice\WorkerSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\VoiceFixtures;
use Tests\TestCase;

/** All LiveKit/provider positive responses here are protocol fixtures, never live audio evidence. */
class VoiceSessionTest extends TestCase
{
    use RefreshDatabase, VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureVoice();
    }

    public function test_the_playground_page_hands_the_browser_the_real_named_session_routes(): void
    {
        $owner = $this->workspaceUser();
        // Regression guard: the browser must receive route() generated endpoints inside the
        // workspace /app prefix; a hardcoded /workspace path 404s every start/poll/stop call.
        $this->actingAs($owner)->get('/app/playground')->assertOk()
            ->assertSee('\\/app\\/voice-sessions', false)
            ->assertSee('\\/app\\/voice-sessions\\/__SESSION__', false)
            ->assertDontSee('\\/workspace\\/voice-sessions', false);
    }

    public function test_missing_infrastructure_never_mints_a_grant_or_creates_a_session(): void
    {
        $owner = $this->workspaceUser();
        config(['voice.enabled' => false]);
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner))
            ->assertStatus(503)->assertJsonPath('code', 'not_configured')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('voice_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_a_real_provider_configuration_is_required_and_modular_models_do_not_substitute(): void
    {
        $owner = $this->workspaceUser();
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner))
            ->assertStatus(409)->assertJsonPath('code', 'provider_not_ready');
        $this->assertDatabaseCount('voice_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_the_short_lived_browser_token_is_room_and_microphone_scoped_and_contains_no_secrets(): void
    {
        $owner = $this->workspaceUser();
        $configuration = $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $session = VoiceSession::query()->findOrFail($result['session']['id']);
        $claims = $this->decodeVoiceToken($result['connection']['token']);
        $this->assertSame('voca-'.$session->id, $claims['video']['room']);
        $this->assertSame('voca-user-'.$session->id, $claims['sub']);
        $this->assertSame(60, $claims['exp'] - $claims['iat']);
        $this->assertSame(['microphone'], $claims['video']['canPublishSources']);
        $this->assertTrue($claims['video']['canPublish']);
        $this->assertTrue($claims['video']['canSubscribe']);
        $this->assertTrue($claims['video']['canPublishData']); // The caller-checked interrupt RPC only; worker text input is disabled.
        $this->assertFalse($claims['video']['canUpdateOwnMetadata']);
        $this->assertFalse($claims['video']['canManageAgentSession']);
        foreach (['roomAdmin', 'roomCreate', 'roomList', 'roomRecord', 'agent', 'ingressAdmin'] as $grant) {
            $this->assertArrayNotHasKey($grant, $claims['video']);
        }
        foreach (['sip', 'roomConfig', 'inference', 'metadata', 'observability', 'attributes', 'name'] as $grant) {
            $this->assertArrayNotHasKey($grant, $claims);
        }
        $this->assertSame($configuration->credential_version, $session->credential_version);
        $this->assertSame($configuration->revision, $session->configuration_revision);
        $this->assertSame('dispatched', $session->state);
        $this->assertNotSame($owner->name, $claims['sub']);
        $this->assertSame(['id', 'state', 'error_code', 'message', 'expires_at', 'started_at', 'ended_at', 'cleanup_pending', 'metrics'], array_keys($result['session']));
        foreach ([$this->fixtureProviderKey, config('voice.livekit_api_secret'), config('voice.worker_secret'), $this->ticketFor($session->id)['ticket'], 'instructions', 'configuration_snapshot', 'api_key'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($result));
            $this->assertStringNotContainsString($secret, json_encode($session));
        }
        $raw = DB::table('voice_sessions')->where('id', $session->id)->first();
        $this->assertStringNotContainsString('Welcome callers', $raw->configuration_snapshot);
        $this->assertStringNotContainsString($this->fixtureProviderKey, json_encode($raw));
        $this->assertSame(hash('sha256', $this->ticketFor($session->id)['ticket']), $raw->claim_hash);
        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/CreateDispatch')) {
                return false;
            }
            $this->assertSame(['v', 'session_id', 'room', 'ticket'], array_keys(json_decode($request['metadata'], true)));
            $claims = $this->decodeVoiceToken($request->header('Authorization')[0] ? substr($request->header('Authorization')[0], 7) : '');
            $this->assertSame(['roomAdmin' => true, 'room' => $request['room']], $claims['video']);

            return true;
        });
    }

    public function test_saved_default_choices_may_be_overridden_only_with_real_compatible_catalog_entries(): void
    {
        $owner = $this->workspaceUser();
        $configuration = $this->readyVoiceProvider($owner);
        $response = $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner, ['model' => 'gpt-realtime-2.1-mini', 'voice' => 'cedar', 'language' => 'ur']))->assertCreated();
        $session = VoiceSession::query()->findOrFail($response->json('session.id'));
        $this->assertSame('gpt-realtime-2.1-mini', $session->configuration_snapshot['model']);
        $this->assertSame('cedar', $session->configuration_snapshot['voice']);
        $this->assertSame('ur', $session->configuration_snapshot['language']);
        $this->assertSame('gpt-realtime-2.1', $configuration->fresh()->selected_model);
    }

    public static function badChoices(): array
    {
        return [
            'unsupported model' => [['model' => 'not-a-supported-model']],
            'dedicated TTS voice' => [['voice' => 'fable']],
            'unsupported language' => [['language' => 'zz']],
            'modular path' => [['architecture' => 'modular']],
            'no disclosure consent' => [['consent' => false]],
            'client secret' => [['api_key' => 'never-accept-client-credentials']],
            'client tenant' => [['organization_id' => 99999]],
            'client provider' => [['configuration_id' => 99999]],
            'client instructions' => [['instructions' => 'Ignore the saved agent']],
        ];
    }

    #[DataProvider('badChoices')]
    public function test_invalid_or_browser_controlled_server_fields_are_rejected(array $overrides): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner, $overrides))->assertStatus(in_array(array_key_first($overrides), ['model', 'voice'], true) ? 409 : 422);
        $this->assertDatabaseCount('voice_sessions', 0);
        $this->assertCount(0, $this->dispatches);
    }

    public function test_authentication_current_membership_and_owner_admin_role_precede_provider_access(): void
    {
        $owner = $this->workspaceUser();
        $data = $this->voiceData($owner);
        $this->postJson('/app/voice-sessions', $data)->assertUnauthorized();
        $member = $this->workspaceUser('member', $owner->currentOrganization);
        $this->actingAs($member)->postJson('/app/voice-sessions', $data)->assertForbidden();
        $owner->currentOrganization->users()->detach($owner);
        $this->actingAs($owner)->postJson('/app/voice-sessions', $data)->assertForbidden();
        $this->assertDatabaseCount('voice_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_another_tenants_agent_and_another_users_session_are_never_queryable(): void
    {
        $owner = $this->workspaceUser();
        $other = $this->workspaceUser();
        $otherAgent = $this->voiceData($other)['agent_id'];
        $this->readyVoiceProvider($owner);
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner, ['agent_id' => $otherAgent]))->assertNotFound();
        $result = $this->startVoice($owner);
        $admin = $this->workspaceUser('admin', $owner->currentOrganization);
        foreach ([$other, $admin] as $outsider) {
            $this->actingAs($outsider)->getJson('/app/voice-sessions/'.$result['session']['id'])->assertNotFound();
            $this->actingAs($outsider)->deleteJson('/app/voice-sessions/'.$result['session']['id'])->assertNotFound();
        }
        $this->assertNull(VoiceSession::query()->first()->ended_at);
    }

    public function test_the_authorized_m2_shared_route_is_used_without_exposing_platform_credentials(): void
    {
        $operator = $this->workspaceUser();
        $shared = $this->readyVoiceProvider($operator, true);
        $owner = $this->workspaceUser();
        $result = $this->startVoice($owner);
        $session = VoiceSession::query()->findOrFail($result['session']['id']);
        $this->assertSame($shared->id, $session->provider_configuration_id);
        $this->assertSame($owner->current_organization_id, $session->organization_id);
        $this->assertStringNotContainsString($this->fixtureProviderKey, json_encode($result));
        $owner->currentOrganization->users()->detach($owner);
        $metadata = $this->ticketFor($session->id);
        $this->workerPost($session->id, 'claim', ['room' => $metadata['room'], 'ticket' => $metadata['ticket'], 'job_id' => 'AJ_test'])->assertStatus(409)->assertJsonPath('code', 'access_revoked');
        $this->assertSame('revoked', $session->fresh()->state);
    }

    public function test_one_active_session_per_actor_is_durable_and_a_confirmed_stop_releases_the_slot(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner))->assertStatus(429)->assertJsonPath('code', 'session_limit');
        $this->deleteJson('/app/voice-sessions/'.$result['session']['id'])->assertOk()->assertJsonPath('session.state', 'ended')->assertJsonPath('session.cleanup_pending', false);
        $ended = VoiceSession::query()->find($result['session']['id']);
        $this->assertNull($ended->configuration_snapshot);
        $this->assertNull($ended->claim_hash);
        $this->assertNull($ended->lease_hash);
        $this->assertNull($ended->active_user_key);
        $this->assertNotNull($ended->room_deleted_at);
        $second = $this->startVoice($owner);
        $this->assertNotSame($result['connection']['room'], $second['connection']['room']);
        $this->assertNotSame($result['session']['id'], $second['session']['id']);
    }

    public function test_a_failed_dispatch_is_terminal_and_a_room_delete_failure_remains_pending_until_confirmed(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $this->failDispatch = $this->failCleanup = true;
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner))->assertStatus(503)->assertJsonPath('code', 'livekit_unavailable')->assertDontSee($this->fixtureProviderKey);
        $session = VoiceSession::query()->first();
        $this->assertSame('failed', $session->state);
        $this->assertTrue($session->cleanup_pending);
        $this->assertNull($session->active_user_key);
        $this->assertNull($session->configuration_snapshot);
        $this->failCleanup = false;
        $this->artisan('voice:reap')->assertSuccessful();
        $this->assertFalse($session->fresh()->cleanup_pending);
        $this->assertNotNull($session->fresh()->room_deleted_at);
    }

    public function test_hard_expiry_is_enforced_by_the_control_plane_and_never_renews_a_grant(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $this->travel(601)->seconds();
        $this->actingAs($owner)->getJson('/app/voice-sessions/'.$result['session']['id'])->assertOk()->assertJsonPath('session.state', 'expired')->assertJsonPath('session.error_code', 'session_expired')->assertJsonMissingPath('connection');
        $session = VoiceSession::query()->find($result['session']['id']);
        $this->assertNull($session->configuration_snapshot);
        $this->assertSame(1, count($this->dispatches));
    }

    public function test_startup_deadline_is_swept_and_only_old_cleaned_metadata_is_pruned(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $this->travel(61)->seconds();
        $this->artisan('voice:reap')->assertSuccessful();
        $session = VoiceSession::query()->find($result['session']['id']);
        $this->assertSame('worker_timeout', $session->error_code);
        $this->assertFalse($session->cleanup_pending);
        $this->travel(8)->days();
        $this->artisan('voice:reap')->assertSuccessful();
        $this->assertDatabaseCount('voice_sessions', 0);
    }

    public function test_disabling_voice_revokes_the_worker_but_can_still_teardown_the_livekit_room(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $result = $this->startVoice($owner);
        $grant = $this->claimVoice($result['session']['id']);
        config(['voice.enabled' => false]);
        $this->workerPost($grant['session_id'], 'heartbeat', $this->pulseData($grant))->assertOk()->assertJsonPath('continue', false)->assertJsonPath('state', 'revoked');
        $session = VoiceSession::query()->find($grant['session_id']);
        $this->assertFalse($session->cleanup_pending);
    }

    public function test_saved_instructions_are_an_immutable_snapshot_and_edits_apply_only_to_the_next_session(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $data = $this->voiceData($owner);
        $result = $this->startVoice($owner);
        $owner->currentOrganization->agents()->whereKey($data['agent_id'])->update(['instructions' => 'New future-only draft instructions, do not use in this session.']);
        $grant = $this->claimVoice($result['session']['id']);
        $this->assertStringStartsWith('Welcome callers.', $grant['configuration']['instructions']);
        $this->assertStringNotContainsString('future-only', json_encode($grant));
        $this->assertSame($this->fixtureProviderKey, $grant['credentials']['api_key']);
    }

    public static function invalidUrls(): array
    {
        return array_map(fn ($url) => [$url], ['https://rtc.example.test', 'ws://rtc.example.test', 'wss://u:p@rtc.example.test', 'wss://rtc.example.test/path', 'wss://rtc.example.test?key=secret', 'wss://rtc.example.test#fragment', "wss://rtc.example.test\n"]);
    }

    #[DataProvider('invalidUrls')]
    public function test_public_or_secret_bearing_livekit_urls_are_not_accepted_as_browser_endpoints(string $url): void
    {
        config(['voice.livekit_url' => $url]);
        $this->assertFalse(app(VoiceInfrastructure::class)->configured());
    }

    public function test_fast_claim_during_dispatch_cannot_be_downgraded_by_the_late_start_response(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $this->onDispatch = function (array $metadata): void {
            $grant = app(WorkerSessions::class)->claim($metadata['session_id'], ['room' => $metadata['room'], 'ticket' => $metadata['ticket'], 'job_id' => 'AJ_test'])->payload();
            app(WorkerSessions::class)->heartbeat($metadata['session_id'], $this->pulseData($grant));
        };
        $result = $this->startVoice($owner);
        $this->assertSame('active', $result['session']['state']);
        $this->assertNotNull($result['session']['started_at']);
    }

    public function test_end_during_dispatch_cannot_issue_a_late_browser_grant_or_resurrect_the_session(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $this->onDispatch = function (array $metadata): void {
            app(VoiceSessions::class)->markTerminal(VoiceSession::query()->find($metadata['session_id']));
        };
        $this->actingAs($owner)->postJson('/app/voice-sessions', $this->voiceData($owner))->assertStatus(409)->assertJsonPath('code', 'session_ended')->assertJsonMissingPath('connection');
        $this->assertSame('ended', VoiceSession::query()->first()->state);
    }

    public function test_gemini_voice_session_creation_and_secure_grant_snapshot(): void
    {
        $owner = $this->workspaceUser();
        $configuration = $this->readyGeminiVoiceProvider($owner);
        $agent = $owner->currentOrganization->agents()->first() ?? $owner->currentOrganization->agents()->create($this->agentData());
        $data = [
            'agent_id' => $agent->id, 'architecture' => 'native_realtime',
            'model' => 'gemini-3.8-live', 'voice' => 'Kore', 'language' => 'en', 'consent' => true,
        ];
        $response = $this->actingAs($owner)->postJson('/app/voice-sessions', $data)->assertCreated();
        $sessionId = $response->json('session.id');
        $session = VoiceSession::query()->findOrFail($sessionId);

        $this->assertSame('gemini-3.8-live', $session->configuration_snapshot['model']);
        $this->assertSame('Kore', $session->configuration_snapshot['voice']);
        $this->assertSame('gemini', $session->configuration_snapshot['provider']);
        $this->assertSame('gemini-transcribe', $session->configuration_snapshot['transcription_model']);

        $grant = $this->claimVoice($sessionId);
        $this->assertSame('gemini', $grant['configuration']['provider']);
        $this->assertSame('gemini-3.8-live', $grant['configuration']['model']);
        $this->assertSame('Kore', $grant['configuration']['voice']);
        $this->assertSame('gemini-transcribe', $grant['configuration']['transcription_model']);
        $this->assertSame($this->fixtureProviderKey, $grant['credentials']['api_key']);
    }

    public function test_gemini_unsupported_model_or_voice_is_rejected(): void
    {
        $owner = $this->workspaceUser();
        $this->readyGeminiVoiceProvider($owner);
        $agent = $owner->currentOrganization->agents()->first() ?? $owner->currentOrganization->agents()->create($this->agentData());

        // Incompatible model
        $this->actingAs($owner)->postJson('/app/voice-sessions', [
            'agent_id' => $agent->id, 'architecture' => 'native_realtime',
            'model' => 'gemini-4-argon', 'voice' => 'Kore', 'language' => 'en', 'consent' => true,
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_selection');

        // Incompatible voice
        $this->actingAs($owner)->postJson('/app/voice-sessions', [
            'agent_id' => $agent->id, 'architecture' => 'native_realtime',
            'model' => 'gemini-3.8-live', 'voice' => 'marin', 'language' => 'en', 'consent' => true,
        ])->assertStatus(409)->assertJsonPath('code', 'invalid_selection');
    }

    public function test_system_health_live_checks_for_livekit_worker_and_queue(): void
    {
        $owner = $this->workspaceUser();
        Cache::put('system:queue_heartbeat', time(), 120);

        $health = app(SystemHealth::class)->checks($owner->currentOrganization);
        $checks = collect($health)->keyBy('label');

        $this->assertTrue($checks['Queue worker']['ok']);
        $this->assertSame('Running', $checks['Queue worker']['status']);

        $this->assertTrue($checks['Voice engine']['ok']);
        $this->assertSame('Connected', $checks['Voice engine']['status']);

        $this->assertTrue($checks['Voice worker']['ok']);
        $this->assertSame('Registered', $checks['Voice worker']['status']);
    }

    public function test_system_health_reports_stalled_or_offline_services_honestly(): void
    {
        $owner = $this->workspaceUser();
        Cache::put('system:queue_heartbeat', time() - 100, 120);

        config(['voice.enabled' => false]);
        $health = app(SystemHealth::class)->checks($owner->currentOrganization);
        $checks = collect($health)->keyBy('label');

        $this->assertFalse($checks['Queue worker']['ok']);
        $this->assertSame('Stalled', $checks['Queue worker']['status']);

        $this->assertFalse($checks['Voice engine']['ok']);
        $this->assertSame('Not configured', $checks['Voice engine']['status']);

        $this->assertFalse($checks['Voice worker']['ok']);
        $this->assertSame('Not configured', $checks['Voice worker']['status']);
    }

    public function test_voice_selection_choices_includes_all_available_realtime_providers(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $this->readyGeminiVoiceProvider($owner);

        $choices = app(VoiceSelection::class)->choices($owner->currentOrganization, $owner);

        $this->assertIsArray($choices['providers']);
        $this->assertCount(2, $choices['providers']);

        $providersById = collect($choices['providers'])->keyBy('id');
        $this->assertTrue($providersById->has('openai'));
        $this->assertTrue($providersById->has('gemini'));

        $this->assertSame('OpenAI', $providersById['openai']['name']);
        $this->assertSame('Google Gemini', $providersById['gemini']['name']);
        $this->assertSame('gemini-3.8-live', $providersById['gemini']['default']['model']);
    }

    public function test_voice_session_store_can_explicitly_target_openai_or_gemini_provider(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $this->readyGeminiVoiceProvider($owner);
        $agent = $owner->currentOrganization->agents()->first() ?? $owner->currentOrganization->agents()->create($this->agentData());

        // Start Gemini session
        $resGemini = $this->actingAs($owner)->postJson('/app/voice-sessions', [
            'agent_id' => $agent->id, 'architecture' => 'native_realtime', 'provider' => 'gemini',
            'model' => 'gemini-3.8-live', 'voice' => 'Kore', 'language' => 'en', 'consent' => true,
        ])->assertCreated();

        $sessionGemini = VoiceSession::query()->findOrFail($resGemini->json('session.id'));
        $this->assertSame('gemini', $sessionGemini->configuration_snapshot['provider']);
        $this->assertSame('gemini-3.8-live', $sessionGemini->configuration_snapshot['model']);

        // End Gemini session so we can start an OpenAI session
        $sessionGemini->forceFill(['ended_at' => now(), 'state' => 'ended', 'active_user_key' => null])->save();

        // Start OpenAI session
        $resOpenAi = $this->actingAs($owner)->postJson('/app/voice-sessions', [
            'agent_id' => $agent->id, 'architecture' => 'native_realtime', 'provider' => 'openai',
            'model' => 'gpt-realtime-2.1-mini', 'voice' => 'marin', 'language' => 'en', 'consent' => true,
        ])->assertCreated();

        $sessionOpenAi = VoiceSession::query()->findOrFail($resOpenAi->json('session.id'));
        $this->assertSame('openai', $sessionOpenAi->configuration_snapshot['provider']);
        $this->assertSame('gpt-realtime-2.1-mini', $sessionOpenAi->configuration_snapshot['model']);
    }

    public function test_voice_session_store_rejects_unsupported_provider_explicit_parameter(): void
    {
        $owner = $this->workspaceUser();
        $this->readyVoiceProvider($owner);
        $agent = $owner->currentOrganization->agents()->first() ?? $owner->currentOrganization->agents()->create($this->agentData());

        $this->actingAs($owner)->postJson('/app/voice-sessions', [
            'agent_id' => $agent->id, 'architecture' => 'native_realtime', 'provider' => 'deepgram',
            'model' => 'gpt-realtime-2.1-mini', 'voice' => 'marin', 'language' => 'en', 'consent' => true,
        ])->assertStatus(422);
    }
}
