<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CallRecord;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspaceUser(): User
    {
        $org = Organization::query()->create([
            'name' => 'Call Workspace',
            'slug' => 'call-workspace',
            'timezone' => 'UTC',
            'default_language' => 'en',
        ]);

        $user = User::factory()->create([
            'current_organization_id' => $org->id,
        ]);

        $org->users()->attach($user->id, ['role' => 'owner']);

        return $user;
    }

    public function test_workspace_user_can_view_call_details(): void
    {
        $user = $this->createWorkspaceUser();
        $agent = Agent::factory()->create([
            'organization_id' => $user->current_organization_id,
            'name' => 'Support Desk Agent',
        ]);

        $call = CallRecord::query()->create([
            'public_id' => '11111111-2222-3333-4444-555555555555',
            'organization_id' => $user->current_organization_id,
            'agent_id' => $agent->id,
            'direction' => 'incoming',
            'status' => 'completed',
            'caller_number' => '+15551112222',
            'destination_number' => '+15553334444',
            'started_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(2),
            'duration_seconds' => 180,
        ]);

        $response = $this->actingAs($user)->get("/app/calls/{$call->public_id}");

        $response->assertOk()
            ->assertSee('Call details')
            ->assertSee('+15551112222')
            ->assertSee('+15553334444')
            ->assertSee('Support Desk Agent')
            ->assertSee('3:00');
    }

    public function test_user_cannot_view_call_from_another_workspace(): void
    {
        $user = $this->createWorkspaceUser();

        $otherOrg = Organization::query()->create([
            'name' => 'Other Workspace',
            'slug' => 'other-workspace',
            'timezone' => 'UTC',
            'default_language' => 'en',
        ]);

        $foreignCall = CallRecord::query()->create([
            'public_id' => '99999999-8888-7777-6666-555555555555',
            'organization_id' => $otherOrg->id,
            'direction' => 'incoming',
            'status' => 'completed',
            'caller_number' => '+15559998888',
        ]);

        $this->actingAs($user)->get("/app/calls/{$foreignCall->public_id}")
            ->assertNotFound();
    }

    public function test_mislinked_agent_does_not_leak_foreign_agent_name(): void
    {
        $user = $this->createWorkspaceUser();

        $otherOrg = Organization::query()->create([
            'name' => 'Foreign Org',
            'slug' => 'foreign-org',
            'timezone' => 'UTC',
            'default_language' => 'en',
        ]);

        $foreignAgent = Agent::factory()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Top Secret Foreign Agent',
        ]);

        $call = CallRecord::query()->create([
            'public_id' => '22222222-3333-4444-5555-666666666666',
            'organization_id' => $user->current_organization_id,
            'agent_id' => $foreignAgent->id,
            'direction' => 'incoming',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get("/app/calls/{$call->public_id}");

        $response->assertOk()
            ->assertDontSee('Top Secret Foreign Agent')
            ->assertSee('No agent assigned');
    }
}
