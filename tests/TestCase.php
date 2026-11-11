<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function workspaceUser(string $role = 'owner', ?Organization $organization = null): User
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => $role]);
        $user->current_organization_id = $organization->id;
        $user->save();

        return $user;
    }

    protected function agentData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Maya',
            'purpose' => 'receptionist',
            'personality' => 'friendly',
            'language' => 'en',
            'instructions' => 'Welcome callers. Use only verified information. Be thoughtful and helpful.',
            'greeting' => 'Hello! I am an AI assistant. How can I help?',
        ], $overrides);
    }
}
