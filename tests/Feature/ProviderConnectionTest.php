<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ProviderConfiguration;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Providers\ProviderConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function configuration($user): ProviderConfiguration
    {
        return app(ProviderConfigurationStore::class)->create($user->currentOrganization, $user, [
            'name' => 'Secure OpenAI', 'provider' => 'openai', 'capability' => 'realtime',
            'api_key' => 'test-secret-never-in-response', 'enabled' => true,
        ])->fresh();
    }

    public static function errorResponses(): array
    {
        return [
            'invalid key' => [401, ['error' => ['message' => 'test-secret-never-in-response']], 'invalid_credentials'],
            'restricted key' => [403, ['error' => ['message' => '<script>test-secret-never-in-response</script>']], 'permissions'],
            'quota' => [429, ['error' => ['code' => 'insufficient_quota']], 'quota'],
            'rate limit' => [429, ['error' => ['code' => 'rate_limit_exceeded']], 'rate_limited'],
            'outage' => [503, ['raw' => 'test-secret-never-in-response'], 'provider_unavailable'],
            'missing resource' => [404, ['message' => 'not found'], 'not_found'],
            'bad request' => [400, ['error' => ['code' => ['malformed'], 'details' => [['reason' => ['not a string']]], 'message' => 'raw test-secret-never-in-response']], 'unexpected_response'],
            'redirect' => [302, '', 'redirect_blocked'],
            'not JSON' => [200, '<html>test-secret-never-in-response</html>', 'unexpected_response'],
            'nonstandard success is not accepted' => [202, ['data' => []], 'unexpected_response'],
            'missing model identifier' => [200, ['data' => [['name' => 'not-a-model-object']]], 'unexpected_response'],
            'invalid schema' => [200, ['data' => ['model' => 'not a list']], 'unexpected_response'],
            'error in 200' => [200, ['error' => ['message' => 'bad']], 'unexpected_response'],
            'oversized body' => [200, str_repeat('x', 2097153), 'response_too_large'],
            'permission is not invalid credentials' => [401, ['detail' => ['status' => 'missing_permissions']], 'permissions'],
            'Google error info' => [400, ['error' => ['code' => 400, 'details' => [['reason' => 'API_KEY_INVALID']]]], 'invalid_credentials'],
            'Google server restriction' => [400, ['error' => ['code' => 400, 'details' => [['reason' => 'API_KEY_IP_ADDRESS_BLOCKED']]]], 'permissions'],
        ];
    }

    #[DataProvider('errorResponses')]
    public function test_real_response_categories_are_persisted_without_raw_errors_or_secrets(int $status, mixed $body, string $reason): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(['api.openai.com/v1/models' => Http::response($body, $status, ['Location' => 'https://attacker.invalid/collect'])]);
        Log::spy();
        $response = $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1]);
        $response->assertSessionHasNoErrors()->assertRedirect();
        $configuration->refresh();
        $this->assertSame('failed', $configuration->connection_status);
        $this->assertSame($reason, $configuration->last_error_code);
        $this->assertSame($status, $configuration->last_http_status);
        $this->assertFalse($configuration->freshCatalog());
        $this->assertNull($configuration->catalog);
        $this->assertNull($configuration->usage_snapshot);
        $this->assertNotNull($configuration->last_tested_at);
        $this->assertNull($configuration->last_success_at);
        $this->assertDatabaseHas('provider_connection_tests', ['provider_configuration_id' => $configuration->id, 'outcome' => 'failed', 'code' => $reason, 'http_status' => $status]);
        $this->assertStringNotContainsString('test-secret-never-in-response', $configuration->connectionTests->toJson());
        $this->assertStringNotContainsString('test-secret-never-in-response', AuditLog::all()->toJson());
        $this->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertSee('Check failed')->assertSee('HTTP '.$status)->assertDontSee('test-secret-never-in-response');
        Log::shouldNotHaveReceived('error');
        Http::assertSentCount(1);
    }

    public function test_network_timeout_is_not_recorded_as_an_invalid_key_and_no_exception_is_logged(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(fn () => throw new ConnectionException('raw TLS error with test-secret-never-in-response'));
        Log::spy();
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertSame('network_error', $configuration->last_error_code);
        $this->assertNull($configuration->last_http_status);
        $this->assertStringNotContainsString('test-secret-never-in-response', $configuration->last_message);
        $this->get(route('workspace.providers.edit', $configuration->id))->assertSee('does not prove the key is invalid');
        Log::shouldNotHaveReceived('error');
    }

    public function test_connection_results_for_a_replaced_key_are_not_applied(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(function () use ($configuration, $user) {
            app(ProviderConfigurationStore::class)->update($configuration, $user, ['revision' => 1, 'name' => 'Rotated during check', 'api_key' => 'new-live-test-sentinel-key', 'enabled' => true]);

            return Http::response(['data' => [['id' => 'gpt-realtime-2.1']]]);
        });
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($message) => str_contains($message, 'did not update'));
        $configuration->refresh();
        $this->assertSame('not_tested', $configuration->connection_status);
        $this->assertSame(2, $configuration->credential_version);
        $this->assertNull($configuration->catalog);
        $this->assertNull($configuration->last_success_at);
        $this->assertSame('new-live-test-sentinel-key', $configuration->api_key);
        $this->assertDatabaseHas('provider_connection_tests', ['provider_configuration_id' => $configuration->id, 'credential_version' => 1, 'applied' => false]);
    }

    public function test_deleted_configuration_is_not_recreated_by_an_in_flight_success(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(function () use ($configuration, $user) {
            app(ProviderConfigurationStore::class)->delete($configuration, $user, 1);

            return Http::response(['data' => [['id' => 'gpt-realtime-2.1']]]);
        });
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertRedirect('/app/providers');
        $this->assertDatabaseCount('provider_configurations', 0);
        $this->assertDatabaseCount('provider_connection_tests', 0);
    }

    public function test_unreadable_ciphertext_is_handled_safely_and_never_attempts_http(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        DB::table('provider_configurations')->where('id', $configuration->id)->update(['api_key' => 'invalid-encrypted-value']);
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame('credential_unreadable', $configuration->fresh()->last_error_code);
        $this->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertDontSee('invalid-encrypted-value')->assertSee('could not be decrypted');
        Http::assertNothingSent();
    }

    public function test_same_configuration_checks_are_locked_and_every_user_is_rate_limited(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        $lock = Cache::lock('provider-test:'.$configuration->id, 45);
        $this->assertTrue($lock->get());
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertSessionHasErrors('connection');
        $lock->release();
        Http::fake(['api.openai.com/v1/models' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('workspace.providers.test', $configuration->id), ['revision' => $configuration->fresh()->revision])->assertRedirect();
        }
        $this->post(route('workspace.providers.test', $configuration->id), ['revision' => $configuration->fresh()->revision])->assertStatus(429);
        Http::assertSentCount(4);
    }

    public function test_history_retention_is_bounded_and_does_not_store_response_payloads(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-realtime-2.1']], 'private' => 'must-not-be-stored'])]);
        $tester = app(ProviderConnectionTester::class);
        for ($i = 0; $i < 23; $i++) {
            $tester->test($configuration->fresh(), $user, $configuration->fresh()->revision);
        }
        $this->assertSame(20, $configuration->connectionTests()->count());
        $this->assertStringNotContainsString('must-not-be-stored', $configuration->connectionTests()->get()->toJson());
        $this->assertStringNotContainsString('must-not-be-stored', $configuration->fresh()->toJson());
    }

    public function test_current_failure_invalidates_old_catalog_even_when_previous_authentication_passed(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(['api.openai.com/v1/models' => Http::sequence()->push(['data' => [['id' => 'gpt-realtime-2.1']]])->push(['error' => ['code' => 'invalid_api_key']], 401)]);
        $tester = app(ProviderConnectionTester::class);
        $tester->test($configuration, $user, 1);
        $configuration->refresh();
        $this->assertNotNull($configuration->last_success_at);
        $tester->test($configuration, $user, $configuration->revision);
        $this->assertSame('failed', $configuration->fresh()->connection_status);
        $this->assertNull($configuration->fresh()->catalog);
        $this->assertNotNull($configuration->fresh()->last_success_at);
        $this->assertFalse($configuration->fresh()->readyForResolution());
    }

    public function test_a_removed_selected_model_is_reported_and_never_silently_replaced(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(['api.openai.com/v1/models' => Http::sequence()
            ->push(['data' => [['id' => 'gpt-realtime-2.1']]])
            ->push(['data' => [['id' => 'gpt-realtime-2.1-mini']]])]);
        $tester = app(ProviderConnectionTester::class);
        $tester->test($configuration, $user, 1);
        $configuration->refresh();
        app(ProviderConfigurationStore::class)->selection($configuration, $user, [
            'revision' => $configuration->revision, 'selected_model' => 'gpt-realtime-2.1', 'selected_voice' => 'marin', 'routing_role' => 'default',
        ]);
        $configuration->refresh();
        $tester->test($configuration, $user, $configuration->revision);
        $configuration->refresh();
        $this->assertSame('authenticated', $configuration->connection_status);
        $this->assertSame('gpt-realtime-2.1', $configuration->selected_model);
        $this->assertFalse($configuration->readyForResolution());
        $this->assertDatabaseHas('provider_connection_tests', ['provider_configuration_id' => $configuration->id, 'code' => 'selection_unavailable']);
        $this->actingAs($user)->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertSee('routing is blocked')->assertSee('No substitution was made.');
    }

    public function test_disabling_during_an_in_flight_check_does_not_revalidate_or_enable_it(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(function () use ($configuration, $user) {
            app(ProviderConfigurationStore::class)->update($configuration, $user, ['revision' => 1, 'name' => 'Disabled', 'enabled' => false]);

            return Http::response(['data' => [['id' => 'gpt-realtime-2.1']]]);
        });
        $result = app(ProviderConnectionTester::class)->test($configuration, $user, 1);
        $this->assertFalse($result->applied);
        $this->assertFalse($configuration->fresh()->enabled);
        $this->assertFalse($configuration->fresh()->freshCatalog());
        $this->assertNull($configuration->fresh()->last_success_at);
    }
}
