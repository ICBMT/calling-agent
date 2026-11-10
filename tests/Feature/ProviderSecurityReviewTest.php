<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Providers\Adapters\ElevenLabsAdapter;
use App\Services\Providers\Adapters\GeminiAdapter;
use App\Services\Providers\Adapters\OpenAiAdapter;
use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Contracts\ProviderAdapter;
use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Providers\ProviderConnectionTester;
use App\Services\Providers\ProviderFailure;
use App\Services\Providers\ProviderHttpClient;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ProviderUnavailable;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Adversarial local review; successful protocol responses here are NOT live acceptance. */
class ProviderSecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Security review connection', 'provider' => 'openai', 'capability' => 'realtime',
            'api_key' => 'review-only-secret-never-a-real-key', 'enabled' => true,
        ], $overrides);
    }

    private function ready(User $owner, string $capability = 'realtime', string $role = 'default'): ProviderConfiguration
    {
        $store = app(ProviderConfigurationStore::class);
        $record = $store->create($owner->currentOrganization, $owner, $this->data(['capability' => $capability]))->fresh();
        app(ProviderConnectionTester::class)->test($record, $owner, $record->revision);
        $record->refresh();
        [$model, $voice] = match ($capability) {
            'stt' => ['gpt-transcribe', null],
            'llm' => ['gpt-6-astra', null],
            'tts' => ['gpt-4o-mini-tts', 'fable'],
            default => ['gpt-realtime-2.1', 'marin'],
        };
        $store->selection($record, $owner, ['revision' => $record->revision, 'selected_model' => $model, 'selected_voice' => $voice, 'routing_role' => $role]);

        return $record->fresh();
    }

    private function modelResponses(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => array_map(fn ($id) => ['id' => $id], [
            'gpt-realtime-2.1', 'gpt-transcribe', 'gpt-6-astra', 'gpt-4o-mini-tts', 'gpt-4.1-nano',
        ])])]);
    }

    public static function debugFailures(): array
    {
        return ['organization HTML' => [false, false], 'organization JSON' => [false, true], 'platform HTML' => [true, false], 'platform JSON' => [true, true]];
    }

    #[DataProvider('debugFailures')]
    public function test_unexpected_provider_errors_never_render_or_log_secrets_even_with_debug_enabled(bool $platform, bool $json): void
    {
        $user = $this->workspaceUser();
        if ($platform) {
            $user->forceFill(['is_platform_admin' => true])->save();
        }
        $secret = $this->data()['api_key'];
        $logPath = storage_path('logs/provider-review-'.bin2hex(random_bytes(8)).'.log');
        config([
            'app.debug' => true, 'logging.default' => 'provider_review',
            'logging.channels.provider_review' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug'],
        ]);
        // Failure after receiving plaintext but inside the real credential-save transaction.
        $this->mock(AuditLogger::class, function ($mock) use ($secret): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Unexpected upstream/request detail '.$secret, 0, new RuntimeException('Previous exception '.$secret)));
        });
        try {
            $this->actingAs($user);
            $path = $platform ? '/app/platform/providers' : '/app/providers';
            $response = $json ? $this->postJson($path, $this->data()) : $this->post($path, $this->data());
            $response->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private')
                ->assertDontSee($secret)->assertDontSee('Unexpected upstream')->assertDontSee('trace')
                ->assertSessionMissing('_old_input.api_key');
            if ($json) {
                $response->assertExactJson(['message' => 'The provider operation could not be completed. Please retry or contact the installation operator.']);
            }
            $this->assertDatabaseCount('provider_configurations', 0);
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
            $this->assertFileExists($logPath);
            $log = file_get_contents($logPath);
            $this->assertStringContainsString('Provider operation failed.', $log);
            $this->assertStringContainsString('RuntimeException', $log);
            $this->assertSame(1, substr_count($log, 'Provider operation failed.'));
            foreach ([$secret, 'Unexpected upstream', 'Previous exception', '[stacktrace]', 'api_key', 'Authorization'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $log);
            }
            Http::assertNothingSent();
        } finally {
            Log::forgetChannel('provider_review');
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    public function test_rejection_statuses_keep_only_safe_rate_limit_headers_and_no_debug_context(): void
    {
        config(['app.debug' => true]);
        $secret = $this->data()['api_key'];
        foreach ([403, 419, 429, 503] as $status) {
            $request = Request::create('/app/providers/1/test', 'POST', ['api_key' => $secret], server: ['HTTP_ACCEPT' => 'application/json']);
            $exception = new HttpException($status, 'Reflected '.$secret, headers: ['Retry-After' => '30', 'X-Upstream-Request' => $secret, 'X-RateLimit-Reset' => $secret]);
            $response = app(ExceptionHandler::class)->render($request, $exception);
            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame('30', $response->headers->get('Retry-After'));
            $this->assertFalse($response->headers->has('X-Upstream-Request'));
            $this->assertFalse($response->headers->has('X-RateLimit-Reset'));
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString('trace', $response->getContent());
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        }
    }

    public static function unauthorizedActors(): array
    {
        return [['member'], ['outsider'], ['foreign operator'], ['revoked platform operator']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_all_mutation_and_connection_services_authorize_without_relying_on_http(string $kind): void
    {
        $owner = $this->workspaceUser();
        $organization = $owner->currentOrganization;
        if ($kind === 'revoked platform operator') {
            $owner->forceFill(['is_platform_admin' => true])->save();
            $organization = null;
        }
        $store = app(ProviderConfigurationStore::class);
        $record = $store->create($organization, $owner, $this->data())->fresh();
        $actor = match ($kind) {
            'member' => $this->workspaceUser('member', $organization),
            'revoked platform operator' => $owner,
            default => $this->workspaceUser(),
        };
        if ($kind === 'foreign operator') {
            $actor->forceFill(['is_platform_admin' => true])->save();
        }
        if ($kind === 'revoked platform operator') {
            // Deliberately keep an old User instance: a revoke must still take effect.
            User::query()->whereKey($owner->id)->update(['is_platform_admin' => false]);
            $this->assertTrue($owner->is_platform_admin);
        }
        $before = $record->toArray();
        $operations = [
            fn () => $store->create($organization, $actor, $this->data()),
            fn () => $store->update($record, $actor, ['revision' => 1, ...$this->data()]),
            fn () => $store->selection($record, $actor, ['revision' => 1, 'selected_model' => 'gpt-realtime-2.1', 'selected_voice' => 'marin', 'routing_role' => 'default']),
            fn () => $store->removeCredential($record, $actor, 1),
            fn () => $store->delete($record, $actor, 1),
            fn () => app(ProviderConnectionTester::class)->test($record, $actor, 1),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A service accepted an unauthorized actor: '.$kind);
            } catch (AuthorizationException|HttpException $exception) {
                $this->assertTrue($exception instanceof AuthorizationException || $exception->getStatusCode() === 403);
            }
        }
        $this->assertSame($before, $record->fresh()->toArray());
        $this->assertDatabaseCount('provider_configurations', 1);
        $this->assertDatabaseCount('provider_connection_tests', 0);
        $this->assertDatabaseCount('audit_logs', 1);
        Http::assertNothingSent();
    }

    public static function resolverActors(): array
    {
        return [['outsider'], ['membership revoked'], ['foreign operator']];
    }

    #[DataProvider('resolverActors')]
    public function test_each_resolver_requires_current_membership_before_querying_any_provider_or_decrypting(string $kind): void
    {
        $owner = $this->workspaceUser();
        $organization = $owner->currentOrganization;
        $actor = $kind === 'membership revoked' ? $owner : $this->workspaceUser();
        if ($kind === 'membership revoked') {
            $organization->users()->detach($actor);
        } elseif ($kind === 'foreign operator') {
            $actor->forceFill(['is_platform_admin' => true])->save();
        }
        $manager = app(ProviderManager::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ([
            fn () => $manager->resolve($organization, Capability::Realtime, $actor),
            fn () => $manager->resolveNativeRealtime($organization, $actor),
            fn () => $manager->resolveModularPipeline($organization, $actor),
        ] as $resolve) {
            try {
                $resolve();
                $this->fail('Resolution accepted an unauthorized organization.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertStringNotContainsString('provider_configurations', json_encode(DB::getQueryLog()));
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    public static function revokedAccess(): array
    {
        return [['membership removed'], ['downgraded'], ['operator revoked'], ['user deleted']];
    }

    #[DataProvider('revokedAccess')]
    public function test_a_late_result_cannot_apply_after_actor_access_is_revoked(string $kind): void
    {
        $user = $this->workspaceUser();
        if ($kind === 'operator revoked') {
            $user->forceFill(['is_platform_admin' => true])->save();
        }
        $organization = $kind === 'operator revoked' ? null : $user->currentOrganization;
        $record = app(ProviderConfigurationStore::class)->create($organization, $user, $this->data());
        Http::fake(function () use ($user, $organization, $kind) {
            match ($kind) {
                'membership removed' => $organization->users()->detach($user),
                'downgraded' => $organization->users()->updateExistingPivot($user->id, ['role' => 'member']),
                'operator revoked' => User::query()->whereKey($user->id)->update(['is_platform_admin' => false]),
                'user deleted' => User::query()->whereKey($user->id)->delete(),
            };

            return Http::response(['data' => [['id' => 'gpt-realtime-2.1']]]);
        });
        $result = app(ProviderConnectionTester::class)->test($record, $user, 1);
        $this->assertFalse($result->applied);
        $this->assertSame('not_tested', $record->fresh()->connection_status);
        $this->assertNull($record->fresh()->catalog);
        $this->assertNull($record->fresh()->last_success_at);
        $this->assertSame(1, $record->fresh()->revision);
        $this->assertFalse($record->fresh()->readyForResolution());
        $this->assertSame($kind === 'user deleted' ? null : $user->id, $result->user_id);
        Http::assertSentCount(1);
    }

    public static function reflectedMetadata(): array
    {
        return [
            'Deepgram language' => ['deepgram', 'language', 'abc-DEFGHIJK'],
            'Deepgram balance' => ['deepgram', 'amount', '12345678'],
            'ElevenLabs language' => ['elevenlabs', 'language', 'abc-DEFGHIJK'],
            'ElevenLabs allowance' => ['elevenlabs', 'amount', '12345678'],
        ];
    }

    #[DataProvider('reflectedMetadata')]
    public function test_reflected_language_or_numeric_usage_secrets_cannot_reach_persistence_or_responses(string $provider, string $field, string $secret): void
    {
        $user = $this->workspaceUser();
        $project = '11111111-2222-4333-8444-555555555555';
        $record = app(ProviderConfigurationStore::class)->create($user->currentOrganization, $user, $this->data([
            'provider' => $provider, 'capability' => $provider === 'deepgram' ? 'stt' : 'tts',
            'api_key' => $secret, 'project_id' => $provider === 'deepgram' ? $project : null,
        ]));
        Http::fake([
            'api.deepgram.com/v1/projects/'.$project => Http::response(['project_id' => $project]),
            'api.deepgram.com/v1/projects/'.$project.'/models*' => Http::response(['stt' => [['canonical_name' => 'nova-3', 'languages' => [$field === 'language' ? $secret : 'en']]]]),
            'api.deepgram.com/v1/projects/'.$project.'/balances' => Http::response(['balances' => [['amount' => (int) $secret, 'units' => 'USD']]]),
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['tier' => 'starter', 'character_count' => $field === 'amount' ? (int) $secret : 1, 'character_limit' => 30000]),
            'api.elevenlabs.io/v1/models' => Http::response([['model_id' => 'eleven_v3', 'can_do_text_to_speech' => true, 'languages' => [['language_id' => $field === 'language' ? $secret : 'en']]]]),
            'api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [['voice_id' => 'ordinary', 'category' => 'premade']], 'has_more' => false]),
        ]);
        Log::spy();
        $this->actingAs($user)->post(route('workspace.providers.test', $record->id), ['revision' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $record->refresh();
        $this->assertSame('failed', $record->connection_status);
        $this->assertSame('unexpected_response', $record->last_error_code);
        $this->assertSame(200, $record->last_http_status);
        $this->assertNull($record->catalog);
        $this->assertNull($record->usage_snapshot);
        $this->assertNull($record->warnings);
        foreach ([$record->toJson(), $record->connectionTests()->get()->toJson(), AuditLog::all()->toJson(), json_encode(session()->all())] as $output) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->get(route('workspace.providers.edit', $record->id))->assertOk()->assertDontSee($secret)->assertSee('Check failed');
        Log::shouldNotHaveReceived('error');
    }

    public function test_final_metadata_guard_protects_against_future_adapters_bypassing_the_normalizer(): void
    {
        $user = $this->workspaceUser();
        $secret = '12345678';
        $record = app(ProviderConfigurationStore::class)->create($user->currentOrganization, $user, $this->data(['api_key' => $secret]));
        $adapter = new class implements ProviderAdapter
        {
            public function discover(ProviderCredentials $credentials, Capability $capability): Discovery
            {
                return new Discovery([], warnings: [$credentials->key()]);
            }
        };
        app()->instance($adapter::class, $adapter);
        config(['providers.definitions.openai.adapter' => $adapter::class]);
        $result = app(ProviderConnectionTester::class)->test($record, $user, 1);
        $this->assertSame('failed', $result->outcome);
        $this->assertSame('unexpected_response', $result->code);
        $this->assertNull($record->fresh()->catalog);
        $this->assertNull($record->fresh()->warnings);
        $credentials = new ProviderCredentials($secret, $secret, $secret);
        $this->assertStringNotContainsString($secret, json_encode($credentials));
        foreach ([[(int) $secret => 'value'], ['value' => (object) ['key' => $secret]]] as $metadata) {
            try {
                $credentials->assertSafeMetadata($metadata);
                $this->fail('Unsafe metadata was accepted.');
            } catch (ProviderFailure $failure) {
                $this->assertSame('unexpected_response', $failure->reason);
                $this->assertStringNotContainsString($secret, (string) $failure);
            }
        }
        Http::assertNothingSent();
    }

    public function test_failure_codes_and_exception_arguments_cannot_become_a_secret_echo(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));
        $secret = $this->data()['api_key'];
        $failure = new ProviderFailure($secret, 999);
        $this->assertSame('unexpected_response', $failure->reason);
        $this->assertNull($failure->httpStatus);
        $this->assertNull($failure->getPrevious());
        $this->assertStringNotContainsString($secret, (string) $failure);
        $this->assertStringNotContainsString($secret, json_encode($failure->getTrace()));
    }

    public static function invalidSelections(): array
    {
        return array_map(fn ($case) => [$case], [
            'wrong model capability', 'TTS-only voice', 'absent capability', 'voice IDs are a string',
            'voice catalog is an object', 'model catalog is an object', 'null model', 'null voice',
            'past shutdown', 'impossible shutdown', 'expired voice', 'malformed expiry', 'impossible expiry',
            'scalar catalog', 'stale evidence', 'wrong credential version', 'unsupported provider category',
        ]);
    }

    #[DataProvider('invalidSelections')]
    public function test_default_and_fallback_routes_fail_closed_for_incompatible_or_malformed_selections(string $case): void
    {
        $this->modelResponses();
        $owner = $this->workspaceUser();
        // A healthy different category is deliberately present; it can never satisfy Realtime.
        $otherCategory = $this->ready($owner, 'tts', 'fallback');
        $candidate = $this->ready($owner);
        $catalog = $candidate->catalog;
        $updates = [];
        switch ($case) {
            case 'wrong model capability': $catalog['models'][0]['capability'] = 'tts';
                break;
            case 'TTS-only voice': $updates['selected_voice'] = 'fable';
                break;
            case 'absent capability': unset($catalog['models'][0]['capability']);
                break;
            case 'voice IDs are a string': $catalog['models'][0]['voice_ids'] = 'marin';
                break;
            case 'voice catalog is an object': $catalog['voices'] = ['marin' => ['id' => 'marin']];
                break;
            case 'model catalog is an object': $catalog['models'] = ['gpt-realtime-2.1' => $catalog['models'][0]];
                break;
            case 'null model': $updates['selected_model'] = null;
                unset($catalog['models'][0]['id']);
                break;
            case 'null voice': $updates['selected_voice'] = null;
                $catalog['models'][0]['voice_ids'] = [null];
                $catalog['voices'] = [['id' => null]];
                break;
            case 'past shutdown': $catalog['models'][0]['shutdown_date'] = now()->subDay()->toDateString();
                break;
            case 'impossible shutdown': $catalog['models'][0]['shutdown_date'] = '2099-02-30';
                break;
            case 'expired voice': $catalog['voices'] = [['id' => 'marin', 'expires_at' => now()->subSecond()->toIso8601ZuluString()]];
                break;
            case 'malformed expiry': $catalog['voices'] = [['id' => 'marin', 'expires_at' => ['2099-01-01']]];
                break;
            case 'impossible expiry': $catalog['voices'] = [['id' => 'marin', 'expires_at' => '2099-02-30T00:00:00Z']];
                break;
            case 'scalar catalog': $catalog = 'not a catalog';
                break;
            case 'stale evidence': $updates['last_tested_at'] = now()->subHours(25);
                break;
            case 'wrong credential version': $updates['tested_version'] = 999;
                break;
            case 'unsupported provider category': $updates['provider'] = 'elevenlabs';
                break;
        }
        $candidate->forceFill(['catalog' => $catalog, ...$updates])->save();
        $this->assertFalse($candidate->fresh()->readyForResolution());
        $manager = app(ProviderManager::class);
        try {
            $manager->resolveNativeRealtime($owner->currentOrganization, $owner);
            $this->fail('An incompatible default was selected.');
        } catch (ProviderUnavailable) {
            $this->assertTrue(true);
        }
        $candidate->forceFill(['routing_role' => 'fallback', 'routing_key' => 'organization:'.$owner->current_organization_id.':realtime:fallback'])->save();
        $primary = $this->ready($owner);
        $primary->forceFill(['connection_status' => 'failed'])->save();
        try {
            $manager->resolveNativeRealtime($owner->currentOrganization, $owner);
            $this->fail('An incompatible fallback was selected.');
        } catch (ProviderUnavailable) {
            $this->assertTrue(true);
        }
        $this->assertTrue($otherCategory->fresh()->readyForResolution());
    }

    public function test_modular_resolution_is_capability_exact_and_authorized_members_may_consume_server_routes(): void
    {
        $this->modelResponses();
        $owner = $this->workspaceUser();
        $member = $this->workspaceUser('member', $owner->currentOrganization);
        $native = $this->ready($owner);
        $expected = [];
        foreach (['stt', 'llm', 'tts'] as $capability) {
            $expected[$capability] = $this->ready($owner, $capability);
        }
        $manager = app(ProviderManager::class);
        $this->assertSame($native->id, $manager->resolveNativeRealtime($owner->currentOrganization, $member)->configurationId);
        foreach ($manager->resolveModularPipeline($owner->currentOrganization, $member) as $capability => $resolved) {
            $this->assertSame($capability, $resolved->capability->value);
            $this->assertSame($expected[$capability]->id, $resolved->configurationId);
            $this->assertNotSame($native->id, $resolved->configurationId);
            $this->assertStringNotContainsString($this->data()['api_key'], json_encode($resolved));
        }
    }

    public function test_gemini_rejects_unsupported_categories_and_permissive_or_impossible_voice_expiries(): void
    {
        $credentials = new ProviderCredentials($this->data()['api_key']);
        try {
            app(GeminiAdapter::class)->discover($credentials, Capability::SpeechToText);
            $this->fail('Gemini STT is not an implemented category.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('unexpected_response', $failure->reason);
        }
        Http::assertNothingSent();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => [['name' => 'models/gemini-3.8-flash-tts']]]),
            'generativelanguage.googleapis.com/v1beta/voices*' => Http::response(['voices' => [
                ['id' => 'relative', 'type' => 'prompted', 'expire_time' => 'tomorrow'],
                ['id' => 'impossible', 'type' => 'prompted', 'expire_time' => '2099-02-30T00:00:00Z'],
                ['id' => 'bad-time', 'type' => 'prompted', 'expire_time' => '2099-01-01T27:00:00Z'],
                ['id' => 'good', 'type' => 'prompted', 'expire_time' => '2099-01-01T01:00:00.123456+01:00'],
            ]]),
        ]);
        $result = app(GeminiAdapter::class)->discover($credentials, Capability::TextToSpeech);
        $this->assertSame(['good'], array_column($result->voices, 'id'));
        $this->assertSame('2099-01-01T00:00:00Z', $result->voices[0]['expires_at']);
        $this->assertTrue(Catalog::validSelection(['models' => $result->models, 'voices' => $result->voices], Capability::TextToSpeech, 'gemini-3.8-flash-tts', 'good', 'gemini'));
    }

    public function test_elevenlabs_malformed_restrictions_never_mean_unrestricted_voice_or_model_access(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['tier' => 'starter', 'character_count' => 0, 'character_limit' => 30000]),
            'api.elevenlabs.io/v1/models' => Http::response([
                ['model_id' => 'eligible', 'can_do_text_to_speech' => true, 'serves_pro_voices' => true],
                ['model_id' => 'null-alpha', 'can_do_text_to_speech' => true, 'requires_alpha_access' => null],
                ['model_id' => 'malformed-alpha', 'can_do_text_to_speech' => true, 'requires_alpha_access' => 'true'],
                ['model_id' => 'string-capability', 'can_do_text_to_speech' => 'true'],
            ]),
            'api.elevenlabs.io/v2/voices*' => Http::response(['has_more' => false, 'voices' => [
                ['voice_id' => 'safe', 'category' => 'premade', 'safety_control' => 'NONE', 'available_for_tiers' => ['starter']],
                ['voice_id' => 'null-tiers', 'available_for_tiers' => null],
                ['voice_id' => 'string-tiers', 'available_for_tiers' => 'pro'],
                ['voice_id' => 'object-tiers', 'available_for_tiers' => ['tier' => 'starter']],
                ['voice_id' => 'invalid-tier', 'available_for_tiers' => [123]],
                ['voice_id' => 'unknown-control', 'safety_control' => 'new-unreviewed-restriction'],
                ['voice_id' => 'unknown-category', 'category' => 'new-unreviewed-category'],
                ['voice_id' => 'bad-quality', 'category' => 'high_quality', 'high_quality_base_model_ids' => 'eligible'],
                ['voice_id' => 'missing-pro-models', 'category' => 'professional'],
            ]]),
        ]);
        $result = app(ElevenLabsAdapter::class)->discover(new ProviderCredentials($this->data()['api_key']), Capability::TextToSpeech);
        $this->assertSame(['eligible'], array_column($result->models, 'id'));
        $this->assertSame(['safe'], array_column($result->voices, 'id'));
        $this->assertSame(['safe'], $result->models[0]['voice_ids']);
    }

    public function test_retired_or_removed_documented_mappings_cannot_be_grandfathered_by_cached_catalogs(): void
    {
        $this->modelResponses();
        $credentials = new ProviderCredentials($this->data()['api_key']);
        $discovery = app(OpenAiAdapter::class)->discover($credentials, Capability::LanguageModel);
        $this->assertNotContains('gpt-4.1-nano', array_column($discovery->models, 'id'));
        $owner = $this->workspaceUser();
        $text = $this->ready($owner, 'llm');
        // A genuinely verified catalog becomes unusable immediately when its official map is withdrawn.
        config(['providers.documented_models.openai.gpt-6-astra' => null]);
        $this->assertTrue($text->freshCatalog());
        $this->assertFalse($text->readyForResolution());
        try {
            app(ProviderConfigurationStore::class)->selection($text, $owner, [
                'revision' => $text->revision, 'selected_model' => 'gpt-6-astra', 'selected_voice' => null, 'routing_role' => 'default',
            ]);
            $this->fail('A withdrawn documented model remained selectable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected_model', $exception->errors());
        }
        try {
            app(ProviderManager::class)->resolve($owner->currentOrganization, Capability::LanguageModel, $owner);
            $this->fail('A withdrawn documented model remained resolvable.');
        } catch (ProviderUnavailable) {
            $this->assertTrue(true);
        }
        $native = $this->ready($owner);
        $mapping = config('providers.documented_models.openai');
        $mapping['gpt-realtime-2.1']['voices'] = ['alloy'];
        config(['providers.documented_models.openai' => $mapping]);
        $this->assertFalse($native->readyForResolution());
        try {
            app(ProviderManager::class)->resolveNativeRealtime($owner->currentOrganization, $owner);
            $this->fail('A removed built-in voice was grandfathered by a cached catalog.');
        } catch (ProviderUnavailable) {
            $this->assertTrue(true);
        }
    }

    public static function sizeGuards(): array
    {
        return [['on_headers', 200], ['progress', null]];
    }

    #[DataProvider('sizeGuards')]
    public function test_transport_size_guards_remain_safe_when_guzzle_wraps_the_failure(string $guard, ?int $httpStatus): void
    {
        $secret = $this->data()['api_key'];
        $called = false;
        Http::fake(function ($request, $options) use ($guard, $secret, &$called): void {
            $called = true;
            try {
                if ($guard === 'on_headers') {
                    $options[$guard](new Response(200, ['Content-Length' => '2097153']));
                } else {
                    $options[$guard](0, 2097153);
                }
                $this->fail('An oversized response passed the streaming guard.');
            } catch (ProviderFailure $failure) {
                throw new RequestException('Raw request '.$secret, new \GuzzleHttp\Psr7\Request('GET', 'https://api.openai.com/v1/models', ['Authorization' => 'Bearer '.$secret]), previous: $failure);
            }
        });
        try {
            app(ProviderHttpClient::class)->get('openai', '/v1/models', new ProviderCredentials($secret));
            $this->fail('A wrapped streaming-size failure was accepted.');
        } catch (ProviderFailure $failure) {
            $this->assertTrue($called);
            $this->assertSame('response_too_large', $failure->reason);
            $this->assertSame($httpStatus, $failure->httpStatus);
            $this->assertNull($failure->getPrevious());
            $this->assertStringNotContainsString($secret, (string) $failure);
        }
    }
}
