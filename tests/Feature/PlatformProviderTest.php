<?php

namespace Tests\Feature;

use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Services\Providers\Capability;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Providers\ProviderConnectionTester;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ProviderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function providerData(array $overrides = []): array
    {
        return array_replace(['provider' => 'openai', 'capability' => 'realtime', 'name' => 'Shared operator connection', 'api_key' => 'platform-test-secret', 'enabled' => '1'], $overrides);
    }

    private function ready($operator, $organization = null, array $overrides = []): ProviderConfiguration
    {
        $store = app(ProviderConfigurationStore::class);
        $record = $store->create($organization, $operator, $this->providerData($overrides))->fresh();
        app(ProviderConnectionTester::class)->test($record, $operator, $record->revision);
        $record->refresh();
        $store->selection($record, $operator, ['revision' => $record->revision, 'selected_model' => 'gpt-realtime-2.1', 'selected_voice' => 'marin', 'routing_role' => 'default']);

        return $record->fresh();
    }

    public function test_registration_or_workspace_settings_cannot_self_grant_platform_access(): void
    {
        $this->post('/register', [
            'name' => 'Not an operator', 'organization_name' => 'Business', 'email' => 'no-privilege@example.com',
            'password' => 'valid-only-password-123', 'password_confirmation' => 'valid-only-password-123',
            'is_platform_admin' => true,
        ])->assertSessionHasNoErrors();
        $user = User::where('email', 'no-privilege@example.com')->firstOrFail();
        $this->assertFalse($user->is_platform_admin);
        $this->put('/app/settings', ['name' => 'Business', 'timezone' => 'UTC', 'default_language' => 'en', 'is_platform_admin' => true])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->get('/app/platform/providers')->assertForbidden();
        $this->post('/app/platform/providers', $this->providerData())->assertForbidden();
        $this->assertDatabaseCount('provider_configurations', 0);
    }

    public function test_operator_provisioning_requires_explicit_server_side_action_and_can_be_revoked(): void
    {
        $user = $this->workspaceUser();
        $this->artisan('voca:platform-admin', ['email' => $user->email])->expectsConfirmation('Grant platform-wide provider management for this account?', 'no')->assertFailed();
        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->artisan('voca:platform-admin', ['email' => $user->email, '--force' => true])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_admin);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => null, 'action' => 'provider.platform_operator_granted']);
        $this->actingAs($user->fresh())->get('/app/platform/providers')->assertOk()->assertSee('Platform-wide scope.');
        $this->artisan('voca:platform-admin', ['email' => $user->email, '--revoke' => true, '--force' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->actingAs($user->fresh())->get('/app/platform/providers')->assertForbidden();
        $this->artisan('voca:platform-admin', ['email' => 'not-present@example.com', '--force' => true])->assertFailed();
    }

    public function test_shared_credentials_are_only_visible_and_mutable_on_separately_authorized_platform_routes(): void
    {
        $operator = $this->workspaceUser();
        $operator->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($operator)->post('/app/platform/providers', $this->providerData())->assertSessionHasNoErrors();
        $record = ProviderConfiguration::firstOrFail();
        $this->assertNull($record->organization_id);
        $this->assertSame('platform', $record->scope);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => null, 'action' => 'provider.created', 'subject_id' => $record->id]);
        $this->get('/app/providers')->assertOk()->assertDontSee('Shared operator connection');
        $this->get(route('workspace.providers.edit', $record->id))->assertNotFound();
        $this->get(route('workspace.platform.providers.edit', $record->id))->assertOk()->assertDontSee('platform-test-secret');
        $owner = $this->workspaceUser();
        $this->actingAs($owner)->get('/app/providers')->assertOk()->assertDontSee('Shared operator connection');
        $this->get(route('workspace.platform.providers.edit', $record->id))->assertForbidden();
        $this->get(route('workspace.providers.edit', $record->id))->assertNotFound();
        $this->post(route('workspace.platform.providers.test', $record->id), ['revision' => 1])->assertForbidden();
        $this->put(route('workspace.platform.providers.update', $record->id), ['name' => 'Steal', 'enabled' => '1', 'revision' => 1])->assertForbidden();
        $this->put(route('workspace.platform.providers.selection', $record->id), [])->assertForbidden();
        $this->delete(route('workspace.platform.providers.credential.destroy', $record->id), ['revision' => 1])->assertForbidden();
        $this->delete(route('workspace.platform.providers.destroy', $record->id), ['revision' => 1])->assertForbidden();
        $this->assertFalse(Gate::forUser($owner)->allows('view', $record));
        $this->assertFalse(Gate::forUser($owner)->allows('manage', $record));
        Http::assertNothingSent();
    }

    public function test_platform_operator_cannot_use_platform_route_to_edit_arbitrary_organization_secrets(): void
    {
        $operator = $this->workspaceUser();
        $operator->forceFill(['is_platform_admin' => true])->save();
        $owner = $this->workspaceUser();
        $record = app(ProviderConfigurationStore::class)->create($owner->currentOrganization, $owner, $this->providerData())->fresh();
        $this->actingAs($operator)->get(route('workspace.platform.providers.edit', $record->id))->assertNotFound();
        $this->get(route('workspace.providers.edit', $record->id))->assertNotFound();
        $this->assertFalse(Gate::forUser($operator)->allows('manage', $record));
        $this->assertFalse(Gate::forUser($operator)->allows('view', $record));
    }

    public function test_server_resolver_can_inherit_a_verified_shared_default_but_local_override_and_scope_boundaries_win(): void
    {
        $operator = $this->workspaceUser();
        $operator->forceFill(['is_platform_admin' => true])->save();
        $owner = $this->workspaceUser();
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-realtime-2.1']]])]);
        $platform = $this->ready($operator);
        $manager = app(ProviderManager::class);
        $this->assertSame($platform->id, $manager->resolveNativeRealtime($owner->currentOrganization, $owner)->configurationId);
        $local = $this->ready($owner, $owner->currentOrganization, ['name' => 'Local override', 'api_key' => 'local-test-key']);
        $this->assertSame($local->id, $manager->resolveNativeRealtime($owner->currentOrganization, $owner)->configurationId);
        // The explicitly chosen local default failing must not silently switch to a platform credential.
        $local->forceFill(['connection_status' => 'failed'])->save();
        try {
            $manager->resolveNativeRealtime($owner->currentOrganization, $owner);
            $this->fail('An unhealthy local override cannot silently change credential scope.');
        } catch (ProviderUnavailable) {
            $this->assertTrue(true);
        }
        $fallback = $this->ready($owner, $owner->currentOrganization, ['name' => 'Local fallback']);
        $store = app(ProviderConfigurationStore::class);
        $store->selection($fallback, $owner, ['revision' => $fallback->revision, 'selected_model' => 'gpt-realtime-2.1', 'selected_voice' => 'marin', 'routing_role' => 'fallback']);
        // Restore the failed local default designation: its failure is still real and resolver must use explicit fallback.
        $local->refresh();
        $local->forceFill(['routing_role' => 'default', 'routing_key' => 'organization:'.$owner->current_organization_id.':realtime:default'])->save();
        $this->assertSame($fallback->id, $manager->resolveNativeRealtime($owner->currentOrganization, $owner)->configurationId);
        $other = $this->workspaceUser();
        $this->assertSame($platform->id, $manager->resolveNativeRealtime($other->currentOrganization, $other)->configurationId);
    }

    public function test_native_realtime_configurations_do_not_implicitly_supply_a_modular_pipeline(): void
    {
        $operator = $this->workspaceUser();
        $operator->forceFill(['is_platform_admin' => true])->save();
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-realtime-2.1']]])]);
        $this->ready($operator);
        $manager = app(ProviderManager::class);
        $this->assertSame(Capability::Realtime, $manager->resolveNativeRealtime($operator->currentOrganization, $operator)->capability);
        $this->expectException(ProviderUnavailable::class);
        $manager->resolveModularPipeline($operator->currentOrganization, $operator);
    }

    public function test_configuration_service_also_rejects_unprivileged_platform_creation(): void
    {
        $user = $this->workspaceUser();
        $this->expectException(HttpException::class);
        app(ProviderConfigurationStore::class)->create(null, $user, $this->providerData());
    }
}
