<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Alex Morgan', 'organization_name' => 'Good Company', 'email' => 'ALEX@example.com',
            'password' => 'a-good-password-2026', 'password_confirmation' => 'a-good-password-2026',
        ], $overrides);
    }

    public function test_signup_creates_an_isolated_owner_workspace_and_hashed_password(): void
    {
        $this->post('/register', $this->registration())->assertRedirect(route('workspace.overview'));
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('alex@example.com', $user->email);
        $this->assertTrue(Hash::check('a-good-password-2026', $user->password));
        $this->assertNotSame('a-good-password-2026', $user->password);
        $this->assertSame(1, Organization::count());
        $this->assertDatabaseHas('organization_user', ['organization_id' => $user->current_organization_id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $user->current_organization_id, 'action' => 'workspace.created']);
        $this->get('/app')->assertOk()->assertSee('Good Company');
    }

    public function test_invalid_signup_does_not_create_orphaned_records(): void
    {
        $this->post('/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_duplicate_normalized_email_is_rejected(): void
    {
        $this->workspaceUser()->update(['email' => 'alex@example.com']);
        $this->post('/register', $this->registration())->assertSessionHasErrors('email');
        $this->assertSame(1, Organization::count());
    }

    public function test_passwords_longer_than_bcrypt_byte_limit_are_rejected(): void
    {
        $password = str_repeat('é', 40).'123';
        $this->post('/register', $this->registration(['password' => $password, 'password_confirmation' => $password]))->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_users_can_log_in_with_case_normalized_email(): void
    {
        $user = $this->workspaceUser();
        $user->update(['email' => 'alex@example.com', 'password' => 'a-good-password-2026']);
        $this->post('/login', ['email' => 'ALEX@example.com', 'password' => 'a-good-password-2026', 'remember' => 1])->assertRedirect(route('workspace.overview'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_password_does_not_authenticate(): void
    {
        $user = $this->workspaceUser();
        $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'absent@example.com', 'password' => 'invalid-password'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'absent@example.com', 'password' => 'invalid-password'])->assertStatus(429);
    }

    public function test_logout_ends_the_session_and_protects_workspace(): void
    {
        $this->actingAs($this->workspaceUser())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_workspace_routes_require_login(): void
    {
        foreach (['/app', '/app/agents', '/app/agents/create', '/app/playground', '/app/calls', '/app/settings', '/app/setup'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_signed_in_users_do_not_see_signup_or_login_forms(): void
    {
        $this->actingAs($this->workspaceUser());
        $this->get('/register')->assertRedirect('/app');
        $this->get('/login')->assertRedirect('/app');
    }
}
