<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_and_soft_delete_a_draft_agent(): void
    {
        $user = $this->workspaceUser();
        $this->actingAs($user)->post('/app/agents', $this->agentData())->assertRedirect();
        $agent = Agent::sole();
        $this->assertSame($user->current_organization_id, $agent->organization_id);
        $this->assertSame('draft', $agent->status);
        $this->get('/app/agents/'.$agent->id.'/edit')->assertOk()->assertSee('Maya');
        $this->put('/app/agents/'.$agent->id, $this->agentData(['name' => 'Maya updated', 'language' => 'ur']))->assertRedirect();
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'name' => 'Maya updated', 'language' => 'ur']);
        $this->delete('/app/agents/'.$agent->id)->assertRedirect('/app/agents');
        $this->assertSoftDeleted('agents', ['id' => $agent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.created', 'subject_id' => $agent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.updated', 'subject_id' => $agent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.deleted', 'subject_id' => $agent->id]);
        $this->get('/app/agents/'.$agent->id.'/edit')->assertNotFound();
    }

    public function test_members_can_read_but_cannot_mutate_agents(): void
    {
        $user = $this->workspaceUser('member');
        $agent = Agent::factory()->create(['organization_id' => $user->current_organization_id]);
        $this->actingAs($user)->get('/app/agents')->assertOk();
        $this->get('/app/agents/'.$agent->id.'/edit')->assertOk()->assertSee('Read-only member access');
        $this->get('/app/agents/create')->assertForbidden();
        $this->post('/app/agents', $this->agentData())->assertForbidden();
        $this->put('/app/agents/'.$agent->id, $this->agentData())->assertForbidden();
        $this->delete('/app/agents/'.$agent->id)->assertForbidden();
        $this->assertDatabaseCount('agents', 1);
        $this->assertNotSoftDeleted('agents', ['id' => $agent->id]);
    }

    public function test_admin_can_create_agents(): void
    {
        $this->actingAs($this->workspaceUser('admin'))->post('/app/agents', $this->agentData())->assertRedirect();
        $this->assertDatabaseCount('agents', 1);
    }

    public function test_incomplete_or_invalid_agent_configuration_is_rejected(): void
    {
        $this->actingAs($this->workspaceUser())->post('/app/agents', $this->agentData([
            'name' => '', 'instructions' => 'Too short', 'language' => 'invented', 'personality' => 'unsupported', 'purpose' => 'unknown',
        ]))->assertSessionHasErrors(['name', 'instructions', 'language', 'personality', 'purpose']);
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_client_cannot_publish_or_choose_an_organization(): void
    {
        $this->actingAs($this->workspaceUser())->post('/app/agents', $this->agentData(['status' => 'active', 'organization_id' => 999]))->assertSessionHasErrors(['status', 'organization_id']);
        $this->assertDatabaseCount('agents', 0);
    }

    public function test_instructions_have_a_server_enforced_size_limit(): void
    {
        $this->actingAs($this->workspaceUser())->post('/app/agents', $this->agentData(['instructions' => str_repeat('a', 12001)]))->assertSessionHasErrors('instructions');
    }

    public function test_search_is_scoped_and_treats_wildcards_as_literal_text(): void
    {
        $user = $this->workspaceUser();
        Agent::factory()->create(['organization_id' => $user->current_organization_id, 'name' => '100% human']);
        Agent::factory()->create(['organization_id' => $user->current_organization_id, 'name' => 'Other agent']);
        Agent::factory()->create(['name' => 'Private foreign agent']);
        $this->actingAs($user)->get('/app/agents?q='.urlencode('%'))->assertOk()->assertSee('100% human')->assertDontSee('Other agent')->assertDontSee('Private foreign agent');
    }

    public function test_agent_content_is_escaped_in_the_interface(): void
    {
        $user = $this->workspaceUser();
        Agent::factory()->create(['organization_id' => $user->current_organization_id, 'name' => '<script>alert(1)</script>']);
        $this->actingAs($user)->get('/app/agents')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_new_agent_uses_workspace_language_preference(): void
    {
        $user = $this->workspaceUser();
        $user->currentOrganization->update(['default_language' => 'ur']);
        $this->actingAs($user)->get('/app/agents/create')->assertOk()->assertViewHas('agent', fn ($agent) => $agent->language === 'ur');
    }
}
