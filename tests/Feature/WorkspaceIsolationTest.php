<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CallRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_agents_cannot_be_listed_read_updated_or_deleted(): void
    {
        $owner = $this->workspaceUser();
        $other = $this->workspaceUser();
        $foreign = Agent::factory()->create(['organization_id' => $other->current_organization_id, 'name' => 'Hidden foreign agent']);
        $this->actingAs($owner)->get('/app/agents')->assertOk()->assertDontSee('Hidden foreign agent');
        $this->get('/app/agents/'.$foreign->id.'/edit')->assertNotFound();
        $this->put('/app/agents/'.$foreign->id, $this->agentData())->assertNotFound();
        $this->delete('/app/agents/'.$foreign->id)->assertNotFound();
        $this->assertDatabaseHas('agents', ['id' => $foreign->id, 'name' => 'Hidden foreign agent', 'deleted_at' => null]);
    }

    public function test_even_a_mislinked_call_never_exposes_a_foreign_agent_name(): void
    {
        $user = $this->workspaceUser();
        $foreign = Agent::factory()->create(['name' => 'Private agent from another tenant']);
        CallRecord::factory()->create(['organization_id' => $user->current_organization_id, 'agent_id' => $foreign->id]);
        $this->actingAs($user)->get('/app/calls')->assertOk()->assertSee('Unassigned')->assertDontSee($foreign->name);
        $this->get('/app')->assertOk()->assertDontSee($foreign->name);
    }

    public function test_a_tampered_active_organization_without_membership_is_rejected(): void
    {
        $user = $this->workspaceUser();
        $other = $this->workspaceUser();
        $user->current_organization_id = $other->current_organization_id;
        $user->save();
        $this->actingAs($user)->get('/app')->assertForbidden();
        $this->get('/app/agents')->assertForbidden();
        $this->get('/app/calls')->assertForbidden();
        $this->get('/app/settings')->assertForbidden();
    }

    public function test_dashboard_metrics_and_call_history_are_tenant_scoped_and_date_filtered(): void
    {
        $owner = $this->workspaceUser();
        CallRecord::factory()->create(['organization_id' => $owner->current_organization_id, 'duration_seconds' => 120]);
        CallRecord::factory()->create(['organization_id' => $owner->current_organization_id, 'duration_seconds' => 200]);
        CallRecord::factory()->create(['organization_id' => $owner->current_organization_id, 'created_at' => now()->subDays(60)]);
        $foreign = CallRecord::factory()->create(['caller_number' => 'foreign-number-unique']);
        $this->actingAs($owner)->get('/app?days=7')->assertOk()->assertViewHas('metrics', fn ($m) => $m['calls'] === 2 && $m['completed'] === 2 && (float) $m['average_duration'] === 160.0);
        $this->get('/app/calls')->assertOk()->assertDontSee(mb_substr($foreign->public_id, 0, 8));
        $this->get('/app?days=90')->assertOk()->assertViewHas('metrics', fn ($m) => $m['calls'] === 3);
    }

    public function test_call_filters_apply_to_real_records_only(): void
    {
        $user = $this->workspaceUser();
        $incoming = CallRecord::factory()->create(['organization_id' => $user->current_organization_id, 'direction' => 'incoming']);
        $outgoing = CallRecord::factory()->create(['organization_id' => $user->current_organization_id, 'direction' => 'outgoing', 'status' => 'failed']);
        $this->actingAs($user)->get('/app/calls?direction=incoming&status=completed')->assertOk()->assertSee(mb_substr($incoming->public_id, 0, 8))->assertDontSee(mb_substr($outgoing->public_id, 0, 8));
        $this->getJson('/app/calls?direction=invalid')->assertUnprocessable();
        $this->getJson('/app?days=99999')->assertUnprocessable();
    }

    public function test_fresh_workspace_has_no_seeded_activity_or_agents(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user)->get('/app')->assertOk()->assertViewHas('metrics', fn ($m) => $m['calls'] === 0 && $m['agents'] === 0 && $m['average_duration'] === null)->assertSee('No agents yet.');
        $this->get('/app/calls')->assertOk()->assertSee('This workspace has no call activity yet.');
        $this->assertDatabaseCount('calls', 0);
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_operator_settings_are_validated_authorized_and_persisted(): void
    {
        $user = $this->workspaceUser();
        $data = ['name' => 'A better company', 'timezone' => 'Asia/Karachi', 'default_language' => 'ur'];
        $this->actingAs($user)->put('/app/settings', $data)->assertRedirect();
        $this->assertDatabaseHas('organizations', ['id' => $user->current_organization_id, ...$data]);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $user->current_organization_id, 'action' => 'workspace.updated']);
        $this->put('/app/settings', [...$data, 'timezone' => 'Moon/Not_A_Zone'])->assertSessionHasErrors('timezone');
        $this->put('/app/settings', [...$data, 'organization_id' => 99])->assertSessionHasErrors('organization_id');
        $member = $this->workspaceUser('member', $user->currentOrganization);
        $this->actingAs($member)->get('/app/settings')->assertOk();
        $this->put('/app/settings', $data)->assertForbidden();
    }

    public function test_playground_and_setup_do_not_claim_operational_voice(): void
    {
        $this->actingAs($this->workspaceUser())->get('/app/playground')->assertOk()->assertSee('Setup prerequisites for real-time voice')->assertSee('Local microphone check. Not an AI conversation.');
        $this->get('/app/setup')->assertOk()->assertSee('Not configured')->assertSee('Not verified')->assertSee('Development roadmap');
    }
}
