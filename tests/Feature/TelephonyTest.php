<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Organization;
use App\Services\Telephony\TelephonyGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelephonyTest extends TestCase
{
    use RefreshDatabase;

    public function test_telephony_gateway_reports_not_configured_when_disabled(): void
    {
        config(['telephony.enabled' => false]);
        $gateway = app(TelephonyGateway::class);
        $health = $gateway->checkHealth();

        $this->assertFalse($health['ok']);
        $this->assertSame('Not configured', $health['status']);
    }

    public function test_telephony_gateway_reports_configured_when_credentials_provided(): void
    {
        config([
            'telephony.enabled' => true,
            'telephony.driver' => 'twilio',
            'telephony.twilio.account_sid' => 'AC1234567890abcdef',
            'telephony.twilio.auth_token' => 'secret_token',
            'telephony.twilio.phone_number' => '+15551234567',
        ]);

        $gateway = app(TelephonyGateway::class);
        $health = $gateway->checkHealth();

        $this->assertTrue($health['ok']);
        $this->assertSame('Configured (Twilio)', $health['status']);
        $this->assertStringContainsString('+15551234567', $health['detail']);
    }

    public function test_inbound_webhook_creates_call_record_and_returns_twiml(): void
    {
        config([
            'telephony.enabled' => true,
            'telephony.driver' => 'twilio',
            'telephony.sip.livekit_sip_uri' => 'sip:voca-telephony@sip.livekit.cloud',
        ]);

        $org = Organization::query()->create([
            'name' => 'Acme Telephony Org',
            'slug' => 'acme-telephony-org',
            'timezone' => 'UTC',
            'default_language' => 'en',
        ]);

        $org->agents()->create([
            'name' => 'Support Desk',
            'purpose' => 'support',
            'personality' => 'friendly',
            'language' => 'en',
            'instructions' => 'Help the customer with their questions.',
            'status' => 'draft',
        ]);

        $response = $this->post('/api/telephony/inbound/twilio', [
            'From' => '+15559876543',
            'To' => '+15551234567',
            'CallSid' => 'CA_test_sid_123',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertStringContainsString('<Dial><Sip>', $response->getContent());

        $this->assertDatabaseHas('calls', [
            'caller_number' => '+15559876543',
            'destination_number' => '+15551234567',
            'direction' => 'incoming',
            'status' => 'in_progress',
        ]);
    }

    public function test_status_callback_webhook_updates_call_record_duration_and_completion(): void
    {
        config(['telephony.enabled' => true]);

        $org = Organization::query()->create([
            'name' => 'Acme Telephony Org',
            'slug' => 'acme-telephony-org',
            'timezone' => 'UTC',
            'default_language' => 'en',
        ]);

        $call = CallRecord::query()->create([
            'public_id' => '12345678-1234-1234-1234-123456789abc',
            'organization_id' => $org->id,
            'direction' => 'incoming',
            'status' => 'in_progress',
            'caller_number' => '+15559876543',
            'destination_number' => '+15551234567',
            'started_at' => now()->subMinutes(2),
        ]);

        $response = $this->postJson('/api/telephony/status/twilio', [
            'From' => '+15559876543',
            'To' => '+15551234567',
            'CallDuration' => '120',
            'CallStatus' => 'completed',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'acknowledged');

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(120, $call->duration_seconds);
        $this->assertNotNull($call->ended_at);
    }

    public function test_inbound_webhook_rejects_unsupported_provider(): void
    {
        $this->postJson('/api/telephony/inbound/unknown_carrier', [])
            ->assertStatus(400);
    }
}
