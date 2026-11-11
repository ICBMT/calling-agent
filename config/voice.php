<?php

return [
    // Opt in only after configuring reachable infrastructure. This flag is not a health check.
    'enabled' => (bool) env('VOICE_ENABLED', false),
    'livekit_url' => env('LIVEKIT_URL', ''),
    'livekit_api_key' => env('LIVEKIT_API_KEY', ''),
    'livekit_api_secret' => env('LIVEKIT_API_SECRET', ''),
    'worker_name' => env('VOICE_WORKER_NAME', 'voca-browser-v1'),
    'worker_key_id' => env('VOICE_WORKER_KEY_ID', 'v1'),
    'worker_secret' => env('VOICE_WORKER_SECRET', ''),
    'worker_health_url' => env('VOICE_WORKER_HEALTH_URL', 'http://127.0.0.1:8081'),
    'allow_insecure_local' => (bool) env('VOICE_ALLOW_INSECURE_LOCAL', false),
    'session_seconds' => 600,
    'startup_seconds' => 60,
    'token_seconds' => 60,
    'heartbeat_seconds' => 10,
    'lease_seconds' => 25,
    'organization_concurrency' => 3,
    'metadata_retention_days' => 7,
    // Separate media factories. OpenAI and Google Gemini are supported native realtime voice providers.
    'native' => [
        'providers' => ['openai', 'gemini'],
        'provider' => 'openai',
        'models' => ['gpt-realtime-2.1', 'gpt-realtime-2.1-mini', 'gpt-realtime-2', 'gpt-realtime-1.5', 'gpt-realtime'],
        'transcription_model' => 'gpt-transcribe',
        'languages' => ['en' => 'English', 'ur' => 'Urdu', 'hi' => 'Hindi'],
        'supported' => [
            'openai' => [
                'name' => 'OpenAI',
                'models' => ['gpt-realtime-2.1', 'gpt-realtime-2.1-mini', 'gpt-realtime-2', 'gpt-realtime-1.5', 'gpt-realtime'],
                'transcription_model' => 'gpt-transcribe',
                'languages' => ['en' => 'English', 'ur' => 'Urdu', 'hi' => 'Hindi'],
            ],
            'gemini' => [
                'name' => 'Google Gemini',
                'models' => ['gemini-3.8-live'],
                'transcription_model' => 'gemini-transcribe',
                'languages' => ['en' => 'English', 'ur' => 'Urdu', 'hi' => 'Hindi'],
            ],
        ],
    ],
    'modular' => ['implemented' => false],
];
