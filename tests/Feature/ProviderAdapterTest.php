<?php

namespace Tests\Feature;

use App\Services\Providers\Adapters\DeepgramAdapter;
use App\Services\Providers\Adapters\ElevenLabsAdapter;
use App\Services\Providers\Adapters\GeminiAdapter;
use App\Services\Providers\Adapters\OpenAiAdapter;
use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use App\Services\Providers\Data\ProviderCredentials;
use App\Services\Providers\ProviderFailure;
use App\Services\Providers\ProviderHttpClient;
use App\Services\Providers\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** HTTP fakes test normalization/protocol logic, NOT live provider acceptance. */
class ProviderAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10T12:00:00Z'));
        Http::preventStrayRequests();
    }

    private function credentials(): ProviderCredentials
    {
        return new ProviderCredentials('private-test-sentinel', '11111111-2222-4333-8444-555555555555', 'org_example');
    }

    public function test_openai_uses_real_list_intersection_not_prefix_guessing_and_excludes_shutdown_models(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-4.1-mini'], ['id' => 'gpt-6-luna'], ['id' => 'gpt-4.1'],
            ['id' => 'gpt-realtime-2.1'], ['id' => 'text-embedding-3-large'], ['id' => 'gpt-4.1-mini-image'],
            ['id' => 'gpt-4o', 'shutdown_date' => '2026-11-09'], ['id' => 'gpt-5', 'shutdown_date' => 'not-a-date'],
        ]])]);
        $result = app(OpenAiAdapter::class)->discover($this->credentials(), Capability::LanguageModel);
        $this->assertSame(['gpt-4.1-mini', 'gpt-6-luna', 'gpt-4.1'], array_column($result->models, 'id'));
        $this->assertSame([], $result->voices);
        $this->assertSame([], $result->usage);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.openai.com/v1/models' && $request->hasHeader('Authorization', 'Bearer private-test-sentinel') && $request->hasHeader('OpenAI-Project', $this->credentials()->projectId));
    }

    public function test_realtime_and_dedicated_tts_have_distinct_documented_voice_sets_and_api_types(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-realtime-2.1'], ['id' => 'tts-1'], ['id' => 'gpt-4o-mini-tts']]])]);
        $adapter = app(OpenAiAdapter::class);
        $realtime = $adapter->discover($this->credentials(), Capability::Realtime);
        $tts = $adapter->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame(['gpt-realtime-2.1'], array_column($realtime->models, 'id'));
        $this->assertCount(10, $realtime->voices);
        $this->assertSame(['tts-1', 'gpt-4o-mini-tts'], array_column($tts->models, 'id'));
        $this->assertCount(9, $tts->models[0]['voice_ids']);
        $this->assertCount(13, $tts->models[1]['voice_ids']);
        $catalog = ['models' => $tts->models, 'voices' => $tts->voices];
        $this->assertFalse(Catalog::validSelection($catalog, Capability::TextToSpeech, 'tts-1', 'ballad', 'openai'));
        $this->assertTrue(Catalog::validSelection($catalog, Capability::TextToSpeech, 'gpt-4o-mini-tts', 'ballad', 'openai'));
        $this->assertFalse(Catalog::validSelection($catalog, Capability::Realtime, 'tts-1', 'alloy', 'openai'));
        $this->travelTo(Carbon::parse('2027-01-06T00:00:00Z'));
        $this->assertSame([], $adapter->discover($this->credentials(), Capability::TextToSpeech)->models);
        $this->assertFalse(Catalog::validSelection($catalog, Capability::TextToSpeech, 'tts-1', 'alloy', 'openai'));
    }

    public function test_documented_shutdown_cannot_be_extended_by_an_inconsistent_upstream_date(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'tts-1', 'shutdown_date' => '2030-01-01']]])]);
        $result = app(OpenAiAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame('2027-01-06', $result->models[0]['shutdown_date']);
    }

    public function test_openai_legacy_transcription_has_a_real_documented_sunset_and_is_not_realtime_voice(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-transcribe'], ['id' => 'whisper-1'], ['id' => 'gpt-4o-mini-transcribe'], ['id' => 'gpt-realtime-2.1']]])]);
        $result = app(OpenAiAdapter::class)->discover($this->credentials(), Capability::SpeechToText);
        $this->assertSame(['gpt-transcribe', 'whisper-1', 'gpt-4o-mini-transcribe'], array_column($result->models, 'id'));
        $this->assertSame('2027-02-26', $result->models[1]['shutdown_date']);
        $this->assertSame([], $result->voices);
        $this->travelTo(Carbon::parse('2027-02-26T00:00:00Z'));
        $this->assertSame(['gpt-transcribe'], array_column(app(OpenAiAdapter::class)->discover($this->credentials(), Capability::SpeechToText)->models, 'id'));
    }

    public function test_gemini_model_and_voice_pagination_uses_documented_headers_and_different_token_keys(): void
    {
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('x-goog-api-key', 'private-test-sentinel'));
            $this->assertStringNotContainsString('private-test-sentinel', $request->url());
            if (str_contains($request->url(), '/models')) {
                return Http::response(isset($request['pageToken'])
                    ? ['models' => [['name' => 'models/gemini-3.8-flash-lite-tts', 'displayName' => 'Flash-Lite speech']]]
                    : ['models' => [['name' => 'models/gemini-3.8-flash-tts'], ['name' => 'models/gemini-3.8-flash'], ['name' => 'models/gemini-unknown-tts']], 'nextPageToken' => 'next-models']);
            }

            return Http::response(isset($request['page_token'])
                ? ['voices' => [['id' => 'voice_custom_1', 'type' => 'prompted', 'display_name' => 'Studio voice', 'expire_time' => '2026-12-01T00:00:00Z']]]
                : ['voices' => [['id' => 'Kore', 'type' => 'prebuilt', 'display_name' => 'Kore']], 'next_page_token' => 'next-voices']);
        });
        $result = app(GeminiAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame(['gemini-3.8-flash-tts', 'gemini-3.8-flash-lite-tts'], array_column($result->models, 'id'));
        $this->assertSame(['Kore', 'voice_custom_1'], $result->models[0]['voice_ids']);
        $this->assertSame(['Kore', 'voice_custom_1'], array_column($result->voices, 'id'));
        Http::assertSentCount(4);
    }

    public function test_gemini_live_uses_only_prebuilt_voices_and_never_classifies_a_tts_model_as_live(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => [['name' => 'models/gemini-3.8-live'], ['name' => 'models/gemini-3.8-flash-tts'], ['name' => 'models/gemini-3.8-flash']]]),
            'generativelanguage.googleapis.com/v1beta/voices*' => Http::response(['voices' => [
                ['id' => 'Kore', 'type' => 'prebuilt'], ['id' => 'voice_custom_1', 'type' => 'replicated'],
                ['id' => 'expired', 'type' => 'prebuilt', 'expire_time' => '2026-01-01T00:00:00Z'], ['id' => 'unknown', 'type' => 'unknown'],
            ]]),
        ]);
        $result = app(GeminiAdapter::class)->discover($this->credentials(), Capability::Realtime);
        $this->assertSame(['gemini-3.8-live'], array_column($result->models, 'id'));
        $this->assertSame(['Kore'], $result->models[0]['voice_ids']);
        $this->assertSame(['Kore'], array_column($result->voices, 'id'));
    }

    public function test_legacy_gemini_tts_cannot_select_a_custom_voice_and_expired_custom_voices_are_excluded(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => [['name' => 'models/gemini-3.1-flash-tts-preview'], ['name' => 'models/gemini-3.8-flash-tts']]]),
            'generativelanguage.googleapis.com/v1beta/voices*' => Http::response(['voices' => [
                ['id' => 'Puck', 'type' => 'prebuilt'], ['id' => 'voice_valid', 'type' => 'replicated', 'expire_time' => '2026-11-11T00:00:00Z'],
                ['id' => 'voice_expired', 'type' => 'replicated', 'expire_time' => '2026-11-09T00:00:00Z'],
            ]]),
        ]);
        $result = app(GeminiAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame(['Puck'], $result->models[0]['voice_ids']);
        $catalog = ['models' => $result->models, 'voices' => $result->voices];
        $this->assertFalse(Catalog::validSelection($catalog, Capability::TextToSpeech, 'gemini-3.1-flash-tts-preview', 'voice_valid', 'gemini'));
        $this->assertTrue(Catalog::validSelection($catalog, Capability::TextToSpeech, 'gemini-3.8-flash-tts', 'voice_valid', 'gemini'));
        $this->travel(2)->days();
        $this->assertFalse(Catalog::validSelection($catalog, Capability::TextToSpeech, 'gemini-3.8-flash-tts', 'voice_valid', 'gemini'));
    }

    public function test_gemini_does_not_request_speech_voices_for_a_text_model_configuration(): void
    {
        Http::fake(['generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => [['name' => 'models/gemini-3.8-flash'], ['name' => 'models/gemini-3.8-flash-tts']]])]);
        $result = app(GeminiAdapter::class)->discover($this->credentials(), Capability::LanguageModel);
        $this->assertSame(['gemini-3.8-flash'], array_column($result->models, 'id'));
        $this->assertSame([], $result->voices);
        Http::assertSentCount(1);
    }

    public function test_repeated_gemini_pagination_tokens_fail_closed_instead_of_accepting_a_partial_catalog(): void
    {
        Http::fake(['generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => [['name' => 'models/gemini-3.8-flash']], 'nextPageToken' => 'same-token'])]);
        try {
            app(GeminiAdapter::class)->discover($this->credentials(), Capability::LanguageModel);
            $this->fail('A looping catalog must not be accepted.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('catalog_limit', $failure->reason);
        }
        Http::assertSentCount(2);
    }

    public function test_gemini_voice_endpoint_failure_is_not_hidden_as_speech_readiness(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => [['name' => 'models/gemini-3.8-live']]]),
            'generativelanguage.googleapis.com/v1beta/voices*' => Http::response(['error' => ['code' => 403]], 403),
        ]);
        $this->expectException(ProviderFailure::class);
        app(GeminiAdapter::class)->discover($this->credentials(), Capability::Realtime);
    }

    public function test_deepgram_authenticates_via_auth_token_and_discovers_models(): void
    {
        Http::fake([
            'api.deepgram.com/v1/auth/token' => Http::response(['api_key_id' => 'dg-key-sentinel', 'key' => 'dg-key-preview']),
            'api.deepgram.com/v1/models*' => Http::response([
                'stt' => [['canonical_name' => 'nova-3-general', 'name' => 'Nova 3', 'languages' => ['en', 'ur', 'invalid language'], 'streaming' => true]],
                'tts' => [['canonical_name' => 'aura-2-thalia-en', 'name' => 'Thalia', 'languages' => ['en']]],
            ]),
        ]);
        $stt = app(DeepgramAdapter::class)->discover($this->credentials(), Capability::SpeechToText);
        $this->assertSame(['nova-3-general'], array_column($stt->models, 'id'));
        $this->assertSame(['en', 'ur'], $stt->models[0]['languages']);
        $this->assertTrue($stt->models[0]['streaming']);
        $this->assertSame([], $stt->usage);

        $tts = app(DeepgramAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame(['aura-2-thalia-en'], $tts->models[0]['voice_ids']);
        $this->assertSame('aura-2-thalia-en', $tts->voices[0]['id']);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.deepgram.com/v1/auth/token' && $request->hasHeader('Authorization', 'Token private-test-sentinel'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.deepgram.com/v1/models');
    }

    public function test_deepgram_discovers_models_without_requiring_project_id(): void
    {
        Http::fake([
            'api.deepgram.com/v1/auth/token' => Http::response(['api_key_id' => 'dg-key-sentinel']),
            'api.deepgram.com/v1/models*' => Http::response([
                'stt' => [['canonical_name' => 'nova-3-general', 'name' => 'Nova 3', 'languages' => ['en'], 'streaming' => true]],
            ]),
        ]);
        $noProjectCreds = new ProviderCredentials('private-test-sentinel', null, null);
        $result = app(DeepgramAdapter::class)->discover($noProjectCreds, Capability::SpeechToText);
        $this->assertSame(['nova-3-general'], array_column($result->models, 'id'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.deepgram.com/v1/auth/token');
    }

    public function test_deepgram_invalid_credentials_returns_invalid_credentials_failure(): void
    {
        Http::fake([
            'api.deepgram.com/v1/auth/token' => Http::response(['err_code' => 'INVALID_AUTH', 'err_msg' => 'Invalid credentials.'], 401),
        ]);
        try {
            app(DeepgramAdapter::class)->discover($this->credentials(), Capability::SpeechToText);
            $this->fail('Invalid credentials must not be accepted.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('invalid_credentials', $failure->reason);
            $this->assertSame(401, $failure->httpStatus);
        }
        Http::assertSentCount(1);
    }

    public function test_deepgram_unexpected_auth_token_response_fails_closed(): void
    {
        Http::fake([
            'api.deepgram.com/v1/auth/token' => Http::response(['missing_api_key_id' => true]),
        ]);
        try {
            app(DeepgramAdapter::class)->discover($this->credentials(), Capability::SpeechToText);
            $this->fail('Malformed auth/token response must not be accepted.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('unexpected_response', $failure->reason);
        }
    }

    public function test_gemini_requests_prebuilt_voices_with_type_filter_and_max_page_size_for_realtime(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [['name' => 'models/gemini-3.8-live', 'supportedGenerationMethods' => ['bidiGenerateContent']]],
            ]),
            'generativelanguage.googleapis.com/v1beta/voices*' => Http::response([
                'voices' => [['id' => 'Kore', 'type' => 'prebuilt', 'display_name' => 'Kore']],
            ]),
        ]);
        $result = app(GeminiAdapter::class)->discover($this->credentials(), Capability::Realtime);
        $this->assertSame(['gemini-3.8-live'], array_column($result->models, 'id'));
        $this->assertSame(['Kore'], $result->models[0]['voice_ids']);

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), '/voices')) {
                return ($request['type'] ?? null) === 'prebuilt'
                    && ($request['page_size'] ?? null) == 1000;
            }
            if (str_contains($request->url(), '/models')) {
                return ($request['pageSize'] ?? null) == 1000;
            }
            return true;
        });
    }

    public function test_gemini_dynamically_verifies_bidi_generate_content_for_realtime(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [
                    ['name' => 'models/gemini-3.8-live', 'supportedGenerationMethods' => ['bidiGenerateContent']],
                    ['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => ['generateContent']],
                ],
            ]),
            'generativelanguage.googleapis.com/v1beta/voices*' => Http::response([
                'voices' => [['id' => 'Puck', 'type' => 'prebuilt']],
            ]),
        ]);
        $realtime = app(GeminiAdapter::class)->discover($this->credentials(), Capability::Realtime);
        $this->assertSame(['gemini-3.8-live'], array_column($realtime->models, 'id'));

        $llm = app(GeminiAdapter::class)->discover($this->credentials(), Capability::LanguageModel);
        $this->assertSame(['gemini-3.8-flash'], array_column($llm->models, 'id'));
    }

    private function fakeElevenLabs(array $voices): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['tier' => 'starter', 'character_count' => 1234, 'character_limit' => 30000, 'open_invoices' => [['private' => 'do-not-store']]]),
            'api.elevenlabs.io/v1/models' => Http::response([
                ['model_id' => 'eleven_flash_v2_5', 'name' => 'Flash', 'can_do_text_to_speech' => true, 'serves_pro_voices' => true, 'languages' => [['language_id' => 'en'], ['language_id' => 'ur']]],
                ['model_id' => 'eleven_v3', 'can_do_text_to_speech' => true, 'serves_pro_voices' => true],
                ['model_id' => 'voice-conversion-only', 'can_do_voice_conversion' => true, 'can_do_text_to_speech' => false],
                ['model_id' => 'alpha-model', 'can_do_text_to_speech' => true, 'requires_alpha_access' => true],
            ]),
            'api.elevenlabs.io/v2/voices*' => Http::response(['voices' => $voices, 'has_more' => false, 'total_count' => 999]),
        ]);
    }

    public function test_elevenlabs_uses_metadata_tiers_and_finetuning_for_model_voice_compatibility(): void
    {
        $this->fakeElevenLabs([
            ['voice_id' => 'ordinary', 'name' => 'Good private-test-sentinel voice', 'category' => 'premade'],
            ['voice_id' => 'pro', 'category' => 'professional', 'fine_tuning' => ['state' => ['eleven_v3' => 'fine_tuned', 'eleven_flash_v2_5' => 'queued']]],
            ['voice_id' => 'quality', 'category' => 'high_quality', 'high_quality_base_model_ids' => ['eleven_v3']],
            ['voice_id' => 'paid-only', 'available_for_tiers' => ['pro']],
            ['voice_id' => 'banned', 'safety_control' => 'BAN'],
        ]);
        $result = app(ElevenLabsAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame(['eleven_flash_v2_5', 'eleven_v3'], array_column($result->models, 'id'));
        $this->assertSame(['ordinary'], $result->models[0]['voice_ids']);
        $this->assertSame(['ordinary', 'pro', 'quality'], $result->models[1]['voice_ids']);
        $this->assertSame('1234', $result->usage[0]['value']);
        $this->assertSame('30000', $result->usage[1]['value']);
        $this->assertStringNotContainsString('private-test-sentinel', json_encode($result));
        $this->assertStringNotContainsString('do-not-store', json_encode($result));
        $this->assertStringContainsString('[redacted]', $result->voices[0]['name']);
        Http::assertSent(fn ($request) => $request->hasHeader('xi-api-key', 'private-test-sentinel'));
    }

    public function test_elevenlabs_public_model_success_does_not_prove_authentication(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['detail' => ['status' => 'invalid_api_key']], 401),
            'api.elevenlabs.io/v1/models' => Http::response([['model_id' => 'public-model', 'can_do_text_to_speech' => true]]),
        ]);
        try {
            app(ElevenLabsAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
            $this->fail('Public catalog must never count as authenticated.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('invalid_credentials', $failure->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_elevenlabs_voice_pagination_uses_has_more_not_unstable_total_count(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['tier' => 'starter', 'character_count' => 0, 'character_limit' => 100]),
            'api.elevenlabs.io/v1/models' => Http::response([['model_id' => 'eleven_v3', 'can_do_text_to_speech' => true]]),
            'api.elevenlabs.io/v2/voices*' => Http::sequence()
                ->push(['voices' => [['voice_id' => 'one']], 'has_more' => true, 'total_count' => 1, 'next_page_token' => 'next-voice'])
                ->push(['voices' => [['voice_id' => 'two']], 'has_more' => false, 'total_count' => 500]),
        ]);
        $result = app(ElevenLabsAdapter::class)->discover($this->credentials(), Capability::TextToSpeech);
        $this->assertSame(['one', 'two'], array_column($result->voices, 'id'));
        Http::assertSent(fn ($request) => ($request['next_page_token'] ?? null) === 'next-voice');
    }

    public function test_transport_is_fixed_https_no_redirect_no_retry_tls_verified_and_time_bounded(): void
    {
        $observed = false;
        Http::fake(function ($request, $options) use (&$observed) {
            $observed = true;
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['cookies']);
            $this->assertLessThanOrEqual(3, $options['connect_timeout']);
            $this->assertLessThanOrEqual(8, $options['timeout']);

            return Http::response(['data' => []]);
        });
        app(ProviderHttpClient::class)->get('openai', '/v1/models', $this->credentials());
        $this->assertTrue($observed);
        Http::assertSentCount(1);
    }

    public static function unsafePaths(): array
    {
        return [
            ['openai', 'https://attacker.invalid/models'], ['openai', '//127.0.0.1/models'],
            ['openai', '/v1/models?api_key=leak'], ['deepgram', '/v1/projects/../../models'],
            ['elevenlabs', '/v1/text-to-speech'], ['unknown', '/v1/models'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_transport_refuses_custom_origins_paths_and_inference_endpoints(string $provider, string $path): void
    {
        try {
            app(ProviderHttpClient::class)->get($provider, $path, $this->credentials());
            $this->fail('Untrusted endpoint must not be requested.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('unexpected_response', $failure->reason);
        }
        Http::assertNothingSent();
    }

    public function test_transport_deadline_expiry_never_sends_another_request(): void
    {
        try {
            app(ProviderHttpClient::class)->get('openai', '/v1/models', $this->credentials(), deadline: microtime(true) - 1);
            $this->fail('Expired deadline must not send HTTP.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('network_error', $failure->reason);
        }
        Http::assertNothingSent();
    }

    public function test_registry_rejects_cross_capability_adapters_and_secret_debug_output_is_redacted(): void
    {
        $this->assertSame(['key' => '[redacted]'], $this->credentials()->__debugInfo());
        $this->assertStringNotContainsString('private-test-sentinel', json_encode($this->credentials()));
        $registry = app(ProviderRegistry::class);
        $this->assertFalse($registry->supports('elevenlabs', Capability::Realtime));
        $this->assertFalse($registry->supports('gemini', Capability::SpeechToText));
        $this->expectException(\InvalidArgumentException::class);
        $registry->adapter('elevenlabs', Capability::Realtime);
    }

    public function test_transport_rejects_credential_reflection_and_unknown_query_keys_before_sending(): void
    {
        foreach ([['pageToken' => 'private-test-sentinel'], ['key' => 'private-test-sentinel'], ['pageToken' => ['nested' => 'value']]] as $query) {
            try {
                app(ProviderHttpClient::class)->get('gemini', '/v1beta/models', $this->credentials(), $query);
                $this->fail('Secrets and unrecognized query parameters cannot enter URLs.');
            } catch (ProviderFailure $failure) {
                $this->assertSame('unexpected_response', $failure->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_credentials_cannot_be_serialized_to_a_queue_session_or_cache(): void
    {
        $this->expectException(\LogicException::class);
        serialize($this->credentials());
    }

    public function test_pagination_bounds_stop_a_large_catalog_before_accepting_partial_results(): void
    {
        config(['providers.max_pages' => 2]);
        Http::fake(['generativelanguage.googleapis.com/v1beta/models*' => Http::sequence()
            ->push(['models' => [['name' => 'models/gemini-3.8-flash']], 'nextPageToken' => 'page-two'])
            ->push(['models' => [['name' => 'models/gemini-3.6-flash']], 'nextPageToken' => 'page-three'])]);
        try {
            app(GeminiAdapter::class)->discover($this->credentials(), Capability::LanguageModel);
            $this->fail('A truncated catalog must not masquerade as a successful discovery.');
        } catch (ProviderFailure $failure) {
            $this->assertSame('catalog_limit', $failure->reason);
        }
        Http::assertSentCount(2);
    }
}
