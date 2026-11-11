<?php

return [
    // Opt in only after configuring verified telephony infrastructure (Twilio, Telnyx, or SIP gateway).
    'enabled' => (bool) env('TELEPHONY_ENABLED', false),
    'driver' => env('TELEPHONY_DRIVER', 'twilio'),
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
        'phone_number' => env('TWILIO_PHONE_NUMBER', ''),
    ],
    'telnyx' => [
        'api_key' => env('TELNYX_API_KEY', ''),
        'public_key' => env('TELNYX_PUBLIC_KEY', ''),
        'phone_number' => env('TELNYX_PHONE_NUMBER', ''),
    ],
    'sip' => [
        'trunk_domain' => env('SIP_TRUNK_DOMAIN', ''),
        'livekit_sip_uri' => env('LIVEKIT_SIP_URI', ''),
    ],
    'record_calls' => (bool) env('TELEPHONY_RECORD_CALLS', false),
    'max_duration_seconds' => (int) env('TELEPHONY_MAX_DURATION_SECONDS', 1800),
];
