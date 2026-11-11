<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ProviderConfiguration;
use App\Services\Providers\ProviderConfigurationStore;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ProviderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'OpenAI realtime', 'provider' => 'openai', 'capability' => 'realtime', 'api_key' => 'test-only-provider-secret-12345', 'enabled' => '1'], $overrides);
    }

    private function configuration($user, array $overrides = []): ProviderConfiguration
    {
        return app(ProviderConfigurationStore::class)->create($user->currentOrganization, $user, $this->data($overrides))->fresh();
    }

    private function authenticate($user, ProviderConfiguration $configuration): ProviderConfiguration
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-realtime-2.1']]])]);
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => $configuration->revision])->assertSessionHasNoErrors()->assertRedirect();

        return $configuration->fresh();
    }

    private function selection(ProviderConfiguration $configuration, array $overrides = []): array
    {
        return array_replace(['revision' => $configuration->revision, 'selected_model' => 'gpt-realtime-2.1', 'selected_voice' => 'marin', 'routing_role' => 'default'], $overrides);
    }

    public function test_save_encrypts_key_and_never_invents_a_connection_or_leaks_to_html_json_audit_or_old_input(): void
    {
        $user = $this->workspaceUser();
        $key = $this->data()['api_key'];
        $this->actingAs($user)->post('/app/providers', $this->data())->assertSessionHasNoErrors()->assertRedirect();
        $configuration = ProviderConfiguration::firstOrFail();
        $stored = DB::table('provider_configurations')->value('api_key');
        $this->assertNotSame($key, $stored);
        $this->assertStringNotContainsString($key, $stored);
        $this->assertSame($key, $configuration->api_key);
        $this->assertSame('not_tested', $configuration->connection_status);
        $this->assertFalse($configuration->readyForResolution());
        $this->assertNull($configuration->last_tested_at);
        $this->assertSame(0, $configuration->connectionTests()->count());
        $this->assertStringNotContainsString($key, $configuration->toJson());
        $this->assertArrayNotHasKey('api_key', $configuration->toArray());
        $this->assertStringNotContainsString($key, json_encode($configuration->__debugInfo()));
        $this->assertStringNotContainsString($key, AuditLog::all()->toJson());
        $this->get('/app/providers')->assertOk()->assertDontSee($key)->assertSee('Not tested');
        $this->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertDontSee($key)->assertSee('Stored securely:');
        Http::assertNothingSent();
    }

    public function test_validation_never_flashes_secret_and_keys_cannot_be_smuggled_into_endpoint_or_scope(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user)->from('/app/providers/create?provider=openai')->post('/app/providers', $this->data([
            'name' => '', 'endpoint' => 'http://169.254.169.254/', 'organization_id' => 999, 'scope' => 'platform', 'connection_status' => 'authenticated',
        ]))->assertSessionHasErrors(['name', 'endpoint', 'organization_id', 'scope', 'connection_status'])
            ->assertSessionMissing('_old_input.api_key');
        $this->assertDatabaseCount('provider_configurations', 0);
        $this->get('/app/providers/create?provider=openai')->assertOk()->assertDontSee($this->data()['api_key']);
        Http::assertNothingSent();
    }

    public function test_invalid_key_newlines_or_whitespace_are_rejected_without_old_input(): void
    {
        $user = $this->workspaceUser();
        foreach (["tokenwith\r\nInjected: bad", ' key-with-leading-space', 'has a space'] as $key) {
            $this->actingAs($user)->post('/app/providers', $this->data(['api_key' => $key]))->assertSessionHasErrors('api_key')->assertSessionMissing('_old_input.api_key');
        }
        $this->assertDatabaseCount('provider_configurations', 0);
    }

    public function test_models_cannot_be_selected_before_a_real_check_or_from_untrusted_payload_catalog(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        $this->actingAs($user)->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration))->assertSessionHasErrors('selected_model');
        $this->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration, ['catalog' => ['models' => [['id' => 'gpt-realtime-2.1']]]]))->assertSessionHasErrors('catalog');
        $this->assertNull($configuration->fresh()->selected_model);
        Http::assertNothingSent();
    }

    public function test_a_genuine_adapter_result_enables_only_discovered_compatible_model_voice_choices(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->authenticate($user, $this->configuration($user));
        $this->assertSame('authenticated', $configuration->connection_status);
        $this->assertTrue($configuration->freshCatalog());
        $this->assertFalse($configuration->readyForResolution());
        $this->assertSame(200, $configuration->last_http_status);
        $this->assertDatabaseHas('provider_connection_tests', ['provider_configuration_id' => $configuration->id, 'outcome' => 'authenticated', 'applied' => true]);
        $this->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertSee('gpt-realtime-2.1')->assertSee('marin')->assertSee('Audio, inference permissions, and telephone calls were not tested.');
        foreach ([['selected_model' => 'not-in-account'], ['selected_voice' => 'not-a-voice']] as $bad) {
            $this->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration, $bad))->assertSessionHasErrors('selected_model');
        }
        $this->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration))->assertSessionHasNoErrors();
        $this->assertTrue($configuration->fresh()->readyForResolution());
        $resolved = app(ProviderManager::class)->resolveNativeRealtime($user->currentOrganization, $user);
        $this->assertSame($configuration->id, $resolved->configurationId);
        $this->assertSame('gpt-realtime-2.1', $resolved->model);
        $this->assertSame($this->data()['api_key'], $resolved->credentials()->key());
        $this->assertStringNotContainsString($this->data()['api_key'], json_encode($resolved));
        $this->get('/app/setup')->assertOk()->assertSee('1 recently verified');
    }

    public function test_key_rotation_clears_verification_catalog_selection_usage_and_routing_but_retains_versioned_history(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->authenticate($user, $this->configuration($user));
        $this->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration))->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->put(route('workspace.providers.update', $configuration->id), ['name' => 'Rotated', 'api_key' => 'new-test-only-secret', 'enabled' => '1', 'revision' => $configuration->revision])->assertSessionHasNoErrors()->assertSessionMissing('_old_input.api_key');
        $configuration->refresh();
        $this->assertSame('new-test-only-secret', $configuration->api_key);
        $this->assertSame(2, $configuration->credential_version);
        $this->assertSame('not_tested', $configuration->connection_status);
        foreach (['catalog', 'selected_model', 'selected_voice', 'usage_snapshot', 'last_tested_at', 'last_success_at', 'routing_key'] as $field) {
            $this->assertNull($configuration->$field, $field.' must be invalidated');
        }
        $this->assertDatabaseHas('provider_connection_tests', ['provider_configuration_id' => $configuration->id, 'credential_version' => 1]);
        $this->assertFalse($configuration->readyForResolution());
        $this->get(route('workspace.providers.edit', $configuration->id))->assertDontSee('new-test-only-secret')->assertSee('Historical credential version.');
    }

    public function test_blank_replacement_keeps_key_but_project_change_invalidates_current_evidence(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->authenticate($user, $this->configuration($user));
        $this->put(route('workspace.providers.update', $configuration->id), ['name' => 'Renamed', 'api_key' => '', 'enabled' => '1', 'revision' => $configuration->revision])->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertSame($this->data()['api_key'], $configuration->api_key);
        $this->assertSame(1, $configuration->credential_version);
        $this->assertTrue($configuration->freshCatalog());
        $this->put(route('workspace.providers.update', $configuration->id), ['name' => 'Changed project', 'api_key' => '', 'project_id' => 'proj_new', 'enabled' => '1', 'revision' => $configuration->revision])->assertSessionHasNoErrors();
        $this->assertFalse($configuration->fresh()->freshCatalog());
        $this->assertSame(2, $configuration->fresh()->credential_version);
    }

    public function test_local_credential_removal_disables_routing_and_test_cannot_succeed_without_a_key(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->authenticate($user, $this->configuration($user));
        $this->delete(route('workspace.providers.credential.destroy', $configuration->id), ['revision' => $configuration->revision])->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertNull(DB::table('provider_configurations')->where('id', $configuration->id)->value('api_key'));
        $this->assertFalse($configuration->enabled);
        $this->assertFalse($configuration->hasCredential());
        $this->assertSame('No credential', $configuration->statusLabel());
        $this->post(route('workspace.providers.test', $configuration->id), ['revision' => $configuration->revision])->assertSessionHasErrors('connection');
        $this->assertSame(1, $configuration->connectionTests()->count());
        $this->delete(route('workspace.providers.destroy', $configuration->id), ['revision' => $configuration->revision])->assertRedirect('/app/providers');
        $this->assertDatabaseMissing('provider_configurations', ['id' => $configuration->id]);
        $this->assertDatabaseCount('provider_connection_tests', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'provider.credential_removed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'provider.deleted']);
    }

    public function test_stale_form_cannot_overwrite_a_rotated_key_or_newer_selection(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        $store = app(ProviderConfigurationStore::class);
        $store->update($configuration, $user, ['name' => 'New edit', 'api_key' => 'newer-test-secret', 'enabled' => true, 'revision' => 1]);
        $this->actingAs($user)->put(route('workspace.providers.update', $configuration->id), ['name' => 'Old edit', 'api_key' => 'stale-test-secret', 'enabled' => '1', 'revision' => 1])->assertSessionHasErrors('revision')->assertSessionMissing('_old_input.api_key');
        $this->assertSame('newer-test-secret', $configuration->fresh()->api_key);
    }

    public function test_only_one_default_and_fallback_per_capability_scope_and_expired_snapshots_never_resolve(): void
    {
        $user = $this->workspaceUser();
        $first = $this->authenticate($user, $this->configuration($user));
        app(ProviderConfigurationStore::class)->selection($first, $user, $this->selection($first));
        $second = $this->authenticate($user, $this->configuration($user, ['name' => 'Second']));
        app(ProviderConfigurationStore::class)->selection($second, $user, $this->selection($second));
        $this->assertSame('none', $first->fresh()->routing_role);
        $this->assertNull($first->fresh()->routing_key);
        $first->refresh();
        app(ProviderConfigurationStore::class)->selection($first, $user, $this->selection($first, ['routing_role' => 'fallback']));
        $second->refresh();
        $second->forceFill(['connection_status' => 'failed'])->save();
        $manager = app(ProviderManager::class);
        $this->assertSame($first->id, $manager->resolveNativeRealtime($user->currentOrganization, $user)->configurationId);
        $this->travel(25)->hours();
        $this->assertFalse($first->fresh()->freshCatalog());
        $this->actingAs($user)->put(route('workspace.providers.selection', $first->id), $this->selection($first->fresh()))->assertSessionHasErrors('selected_model');
        $this->expectException(ProviderUnavailable::class);
        $manager->resolveNativeRealtime($user->currentOrganization, $user);
    }

    public function test_disabling_a_configuration_removes_routing_and_it_cannot_be_designated_default(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->authenticate($user, $this->configuration($user));
        $store = app(ProviderConfigurationStore::class);
        $store->selection($configuration, $user, $this->selection($configuration));
        $configuration->refresh();
        $this->put(route('workspace.providers.update', $configuration->id), ['name' => $configuration->name, 'enabled' => '0', 'revision' => $configuration->revision])->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertFalse($configuration->readyForResolution());
        $this->assertSame('none', $configuration->routing_role);
        $this->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration))->assertSessionHasErrors('routing_role');
    }

    public function test_unsupported_provider_capabilities_and_invalid_deepgram_projects_are_rejected(): void
    {
        $user = $this->workspaceUser();
        foreach ([['provider' => 'unknown'], ['provider' => 'elevenlabs', 'capability' => 'realtime'], ['provider' => 'deepgram', 'capability' => 'stt', 'project_id' => '../../not-a-project']] as $data) {
            $this->actingAs($user)->post('/app/providers', $this->data($data))->assertSessionHasErrors()->assertSessionMissing('_old_input.api_key');
        }
        $this->assertDatabaseCount('provider_configurations', 0);
        Http::assertNothingSent();
    }

    public function test_member_can_view_redacted_status_but_every_mutation_is_forbidden(): void
    {
        $owner = $this->workspaceUser();
        $configuration = $this->configuration($owner);
        $member = $this->workspaceUser('member', $owner->currentOrganization);
        $this->actingAs($member)->get('/app/providers')->assertOk()->assertDontSee('Add OpenAI')->assertDontSee($this->data()['api_key']);
        $this->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertSee('Read-only access.')->assertDontSee('name="api_key"', false);
        $this->get('/app/providers/create?provider=openai')->assertForbidden();
        $this->post('/app/providers', $this->data())->assertForbidden();
        $this->put(route('workspace.providers.update', $configuration->id), [])->assertForbidden();
        $this->put(route('workspace.providers.selection', $configuration->id), [])->assertForbidden();
        $this->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertForbidden();
        $this->delete(route('workspace.providers.credential.destroy', $configuration->id), ['revision' => 1])->assertForbidden();
        $this->delete(route('workspace.providers.destroy', $configuration->id), ['revision' => 1])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_other_workspace_and_platform_resources_are_not_reachable_by_id_or_query_scope(): void
    {
        $user = $this->workspaceUser();
        $foreign = $this->workspaceUser();
        $configuration = $this->configuration($foreign, ['name' => 'Private foreign provider']);
        $this->actingAs($user)->get('/app/providers?scope=platform&organization_id='.$foreign->current_organization_id)->assertOk()->assertDontSee('Private foreign provider');
        $this->get(route('workspace.providers.edit', $configuration->id))->assertNotFound();
        $this->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertNotFound();
        $this->put(route('workspace.providers.update', $configuration->id), ['name' => 'Steal', 'enabled' => '1', 'revision' => 1])->assertNotFound();
        $this->put(route('workspace.providers.selection', $configuration->id), $this->selection($configuration))->assertNotFound();
        $this->delete(route('workspace.providers.credential.destroy', $configuration->id), ['revision' => 1])->assertNotFound();
        $this->delete(route('workspace.providers.destroy', $configuration->id), ['revision' => 1])->assertNotFound();
        $this->get('/app/platform/providers')->assertForbidden();
        $this->post('/app/platform/providers', $this->data())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_organization_admin_can_manage_and_public_provider_routes_require_authentication(): void
    {
        $this->get('/app/providers')->assertRedirect('/login');
        $this->post('/app/providers', $this->data())->assertRedirect('/login');
        $admin = $this->workspaceUser('admin');
        $this->actingAs($admin)->post('/app/providers', $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('provider_configurations', ['organization_id' => $admin->current_organization_id]);
        $this->get('/app/providers/compare')->assertOk()->assertSee('No guaranteed prices or latency.');
    }

    public function test_fresh_catalog_with_no_supported_models_is_not_resolvable_and_ui_is_honest(): void
    {
        $user = $this->workspaceUser();
        $configuration = $this->configuration($user);
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'text-embedding-3-large'], ['id' => 'new-undocumented-model']]])]);
        $this->actingAs($user)->post(route('workspace.providers.test', $configuration->id), ['revision' => 1])->assertSessionHasNoErrors();
        $configuration->refresh();
        $this->assertSame('authenticated', $configuration->connection_status);
        $this->assertSame([], $configuration->catalog['models']);
        $this->assertFalse($configuration->readyForResolution());
        $this->get(route('workspace.providers.edit', $configuration->id))->assertOk()->assertSee('no documented compatible models')->assertDontSee('value="new-undocumented-model"', false);
    }
}
