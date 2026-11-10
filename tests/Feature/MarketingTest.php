<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingTest extends TestCase
{
    public function test_public_pages_are_available_and_honest_about_voice_status(): void
    {
        $this->get('/')->assertOk()->assertSee('Every call.')->assertSee('FOUNDATION RELEASE / MADE WITH INTENTION', false)->assertSee('Not in this release.');
        $this->get('/privacy')->assertOk()->assertSee('It is not uploaded, recorded, or stored.');
        $this->get('/register')->assertOk()->assertSee('Create my workspace');
        $this->get('/login')->assertOk()->assertSee('Hello again');
    }

    public function test_security_headers_and_private_auth_cache_policy_are_present(): void
    {
        $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(self)');
        $response = $this->get('/login');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_unknown_routes_return_404(): void
    {
        $this->get('/not-a-real-page')->assertNotFound();
    }
}
