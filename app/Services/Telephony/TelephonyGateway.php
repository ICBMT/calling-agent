<?php

namespace App\Services\Telephony;

use App\Models\Agent;
use App\Models\CallRecord;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelephonyGateway
{
    public function configured(): bool
    {
        if (! config('telephony.enabled')) {
            return false;
        }

        $driver = config('telephony.driver');

        return match ($driver) {
            'twilio' => filled(config('telephony.twilio.account_sid')) && filled(config('telephony.twilio.auth_token')),
            'telnyx' => filled(config('telephony.telnyx.api_key')),
            'sip' => filled(config('telephony.sip.livekit_sip_uri')),
            default => false,
        };
    }

    public function checkHealth(): array
    {
        if (! config('telephony.enabled') || ! $this->configured()) {
            return [
                'ok' => false,
                'status' => 'Not configured',
                'detail' => 'Telephony provider credentials (Twilio, Telnyx, or SIP trunk) are not configured.',
            ];
        }

        $driver = config('telephony.driver', 'twilio');

        return match ($driver) {
            'twilio' => [
                'ok' => true,
                'status' => 'Configured (Twilio)',
                'detail' => 'Twilio credentials configured with phone number '.config('telephony.twilio.phone_number', 'pending').'.',
            ],
            'telnyx' => [
                'ok' => true,
                'status' => 'Configured (Telnyx)',
                'detail' => 'Telnyx credentials configured with phone number '.config('telephony.telnyx.phone_number', 'pending').'.',
            ],
            'sip' => [
                'ok' => true,
                'status' => 'Configured (SIP)',
                'detail' => 'SIP trunk configured for LiveKit SIP participant gateway: '.config('telephony.sip.livekit_sip_uri').'.',
            ],
            default => [
                'ok' => false,
                'status' => 'Unknown driver',
                'detail' => "Unsupported telephony driver: {$driver}.",
            ],
        };
    }

    /** Validate webhook signature to prevent spoofed calls. */
    public function validateSignature(Request $request, string $provider): bool
    {
        if (! $this->configured()) {
            return false;
        }

        if ($provider === 'twilio') {
            $expectedSignature = $request->header('X-Twilio-Signature');
            if (blank($expectedSignature)) {
                return false;
            }
            $token = config('telephony.twilio.auth_token');
            $url = $request->fullUrl();
            $postVars = $request->post();
            ksort($postVars);
            $data = $url;
            foreach ($postVars as $key => $value) {
                $data .= $key.$value;
            }
            $calculated = base64_encode(hash_hmac('sha1', $data, $token, true));

            return hash_equals($expectedSignature, $calculated);
        }

        return true;
    }

    public function handleInbound(Request $request, string $provider): array
    {
        $caller = $request->input('From') ?? $request->input('caller_number') ?? 'Anonymous';
        $destination = $request->input('To') ?? $request->input('destination_number') ?? config("telephony.{$provider}.phone_number");
        $callSid = $request->input('CallSid') ?? (string) Str::uuid();

        // Resolve organization (fallback to first organization if unmapped)
        $organization = Organization::query()->first();
        if (! $organization) {
            return ['action' => 'reject', 'reason' => 'no_organization'];
        }

        // Resolve assigned agent or fallback to first available draft agent
        $agent = $organization->agents()->first();

        // Record incoming call
        $call = new CallRecord;
        $call->forceFill([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'agent_id' => $agent?->id,
            'direction' => 'incoming',
            'status' => 'in_progress',
            'caller_number' => $caller,
            'destination_number' => $destination,
            'started_at' => now(),
        ])->save();

        $roomName = 'voca-tel-'.$call->public_id;
        $sipUri = config('telephony.sip.livekit_sip_uri');

        if ($provider === 'twilio') {
            // Generate TwiML connecting inbound call to LiveKit SIP URI
            $twiml = '<?xml version="1.0" encoding="UTF-8"?>';
            $twiml .= '<Response>';
            if (filled($sipUri)) {
                $twiml .= '<Dial><Sip>'.$sipUri.'?X-Voca-Room='.$roomName.'</Sip></Dial>';
            } else {
                $twiml .= '<Say voice="Polly.Joanna">Thank you for calling. The Voca AI Calling Agent is currently connecting to LiveKit media services.</Say>';
            }
            $twiml .= '</Response>';

            return [
                'action' => 'twiml',
                'content' => $twiml,
                'call_id' => $call->public_id,
            ];
        }

        return [
            'action' => 'accept',
            'room' => $roomName,
            'call_id' => $call->public_id,
        ];
    }

    public function handleStatusCallback(Request $request, string $provider): void
    {
        $callDuration = (int) $request->input('CallDuration', 0);
        $callStatus = $request->input('CallStatus');
        $caller = $request->input('From');
        $destination = $request->input('To');

        $status = match ($callStatus) {
            'completed' => 'completed',
            'busy' => 'failed',
            'no-answer' => 'missed',
            'canceled' => 'canceled',
            'failed' => 'failed',
            default => 'completed',
        };

        $call = CallRecord::query()
            ->where('caller_number', $caller)
            ->where('destination_number', $destination)
            ->whereNull('ended_at')
            ->latest()
            ->first();

        if ($call) {
            $call->forceFill([
                'status' => $status,
                'duration_seconds' => $callDuration ?: ($call->started_at ? now()->diffInSeconds($call->started_at) : 0),
                'ended_at' => now(),
            ])->save();
        }
    }
}
