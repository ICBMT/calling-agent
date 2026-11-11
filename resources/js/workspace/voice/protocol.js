const terminalStates = new Set(['ended', 'failed', 'expired', 'revoked']);
const kinds = new Set([
    'ready',
    'agent_state',
    'user_state',
    'user_partial',
    'assistant_partial',
    'transcript',
    'transcript_unavailable',
    'metrics',
    'interrupted',
    'error',
]);
const states = new Set(['initializing', 'idle', 'listening', 'thinking', 'speaking', 'away']);
const metricLimits = {
    provider_first_audio_ms: 120000,
    worker_turn_ms: 120000,
    input_tokens: 100000000,
    output_tokens: 100000000,
    input_audio_tokens: 100000000,
    output_audio_tokens: 100000000,
    turns: 10000,
    interruptions: 10000,
};
const errors = {
    not_configured:
        'LiveKit and the voice worker need installation setup. No AI connection was made.',
    provider_not_ready:
        'Set up and test a native realtime default in Providers, then refresh this playground.',
    unsupported_provider:
        'The voice engine supports OpenAI Realtime and Google Gemini Live. Other providers are not connected yet.',
    invalid_selection: 'The model or voice is no longer compatible. Recheck Providers and refresh.',
    session_limit:
        'A session is already open, cleanup is pending, or the workspace limit was reached.',
    livekit_unavailable:
        'LiveKit could not confirm the operation. Check its deployment and network.',
    session_expired: 'The session reached its time limit. Start a new session to continue.',
    worker_timeout:
        'The worker did not start or lost its authorization heartbeat. Check its service.',
    access_revoked: 'The session stopped because access, the saved agent or its provider changed.',
    session_ended:
        'This session has ended. Start a new session; old conversations are not replayed.',
    provider_auth:
        'The realtime provider rejected the key or model access. Check the encrypted key, project permissions and billing in Providers.',
    provider_quota:
        'The realtime provider reported a quota or rate limit. Check its usage and billing before starting again.',
    provider_timeout: 'The audio provider timed out. The session was stopped, not replayed.',
    provider_error:
        'The audio provider could not continue. Check Realtime and transcription access.',
    participant_timeout:
        'The browser did not join in time. Start a new session when you are ready.',
    browser_disconnected: 'The audio connection could not recover. Start a fresh session.',
    invalid_request:
        'Refresh the playground and check the selected draft, model, voice and language.',
    microphone_denied:
        'Microphone permission was not granted. Allow it in browser settings. If this is an embedded preview, open the app in its own tab.',
    microphone_missing: 'No microphone was found. Connect one and try again.',
    microphone_busy: 'The microphone is busy or unavailable. Close other audio apps and try again.',
    microphone_timeout: 'Microphone permission is still pending. Allow it, then start again.',
    insecure_browser:
        'Voice needs HTTPS and a supported WebRTC browser. Open the app in a secure tab.',
    connection_timeout:
        'The WebRTC connection took too long. Check LiveKit, TLS, firewall and TURN.',
    connection_error:
        'The WebRTC room could not connect. Check LiveKit, the network and your browser.',
    worker_not_ready:
        'The room connected, but the named voice worker did not become ready in time.',
    interrupted_failed: 'Stopping the response was not confirmed. Audio was closed for safety.',
};

export function errorMessage(code, status = null) {
    if (status === 401 || status === 419)
        return 'Your sign-in or security token expired. Refresh the page and sign in again.';
    if (status === 403) return 'You no longer have permission to run this workspace voice session.';
    if (status === 429 && !errors[code]) return 'Too many requests. Wait a minute and try again.';
    return (
        errors[code] ||
        'The voice operation could not be completed. Refresh or contact the operator.'
    );
}

export function microphoneCode(error) {
    return (
        {
            NotAllowedError: 'microphone_denied',
            PermissionDeniedError: 'microphone_denied',
            NotFoundError: 'microphone_missing',
            DevicesNotFoundError: 'microphone_missing',
            NotReadableError: 'microphone_busy',
            TrackStartError: 'microphone_busy',
        }[error?.name] || 'connection_error'
    );
}

export function isTerminal(state) {
    return terminalStates.has(state);
}

export function numericMetrics(values) {
    if (!values || typeof values !== 'object' || Array.isArray(values)) return {};
    return Object.fromEntries(
        Object.entries(values).filter(
            ([key, value]) =>
                Object.hasOwn(metricLimits, key) &&
                Number.isFinite(value) &&
                value >= 0 &&
                value <= metricLimits[key],
        ),
    );
}

export function parsePacket(bytes, sessionId) {
    if (!bytes || bytes.byteLength > 15360) return null;
    try {
        const packet = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
        if (
            packet.v !== 1 ||
            packet.session_id !== sessionId ||
            !Number.isInteger(packet.seq) ||
            packet.seq < 1 ||
            packet.seq > 100000 ||
            !Number.isFinite(packet.sent_at) ||
            !kinds.has(packet.type)
        )
            return null;
        if (
            ['user_partial', 'assistant_partial', 'transcript'].includes(packet.type) &&
            (typeof packet.text !== 'string' ||
                [...packet.text].length > 3000 ||
                typeof packet.id !== 'string' ||
                !/^[a-f0-9-]{32,36}$/.test(packet.id) ||
                typeof packet.final !== 'boolean')
        )
            return null;
        if (
            packet.type === 'transcript' &&
            (!['user', 'assistant'].includes(packet.role) ||
                !Number.isFinite(packet.created_at) ||
                typeof packet.interrupted !== 'boolean')
        )
            return null;
        if (['agent_state', 'user_state'].includes(packet.type) && !states.has(packet.state))
            return null;
        if (packet.type === 'error' && !Object.hasOwn(errors, packet.code)) return null;
        return packet;
    } catch {
        return null;
    }
}

export function formatMilliseconds(value) {
    return Number.isFinite(value) && value >= 0 ? `${Math.round(value)} ms` : 'Not measured';
}

export function boundedTranscript(items, packet) {
    const entry = {
        id: packet.id,
        role: packet.role,
        text: packet.text,
        interrupted: packet.interrupted,
        created_at: packet.created_at,
    };
    return [...items.filter((item) => item.id !== entry.id), entry]
        .sort((a, b) => a.created_at - b.created_at)
        .slice(-100);
}
