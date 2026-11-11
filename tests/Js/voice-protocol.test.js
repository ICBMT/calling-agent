import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
    boundedTranscript,
    errorMessage,
    formatMilliseconds,
    isTerminal,
    microphoneCode,
    numericMetrics,
    parsePacket,
} from '../../resources/js/workspace/voice/protocol.js';
import { audioPerformance } from '../../resources/js/workspace/voice/performance.js';
import { voicePlayground } from '../../resources/js/workspace/voice/playground.js';

describe('Voice Protocol & Packet Validation', () => {
    const sessionId = '0193132e-4b2a-7142-9218-356a7bc34e21';

    function encode(obj) {
        return new TextEncoder().encode(JSON.stringify(obj));
    }

    it('rejects null, empty, or oversized packets', () => {
        assert.equal(parsePacket(null, sessionId), null);
        assert.equal(parsePacket(new Uint8Array(0), sessionId), null);
        assert.equal(parsePacket(new Uint8Array(15361), sessionId), null);
    });

    it('rejects malformed JSON or invalid UTF-8 bytes', () => {
        assert.equal(parsePacket(new Uint8Array([0xff, 0xfe, 0xfd]), sessionId), null);
        assert.equal(parsePacket(new TextEncoder().encode('{invalid-json'), sessionId), null);
    });

    it('rejects packets with incorrect version, session mismatch, or bad sequence', () => {
        const base = { v: 1, session_id: sessionId, seq: 1, sent_at: 1700000000, type: 'ready' };

        assert.equal(parsePacket(encode({ ...base, v: 2 }), sessionId), null);
        assert.equal(
            parsePacket(encode({ ...base, session_id: 'wrong-session' }), sessionId),
            null,
        );
        assert.equal(parsePacket(encode({ ...base, seq: 0 }), sessionId), null);
        assert.equal(parsePacket(encode({ ...base, seq: -1 }), sessionId), null);
        assert.equal(parsePacket(encode({ ...base, seq: 1.5 }), sessionId), null);
        assert.equal(parsePacket(encode({ ...base, seq: 100001 }), sessionId), null);
        assert.equal(parsePacket(encode({ ...base, sent_at: 'now' }), sessionId), null);
    });

    it('validates agent_state and user_state against allowed states', () => {
        const base = { v: 1, session_id: sessionId, seq: 2, sent_at: 1700000001 };

        const validAgent = parsePacket(
            encode({ ...base, type: 'agent_state', state: 'speaking' }),
            sessionId,
        );
        assert.ok(validAgent);
        assert.equal(validAgent.state, 'speaking');

        const invalidAgent = parsePacket(
            encode({ ...base, type: 'agent_state', state: 'dancing' }),
            sessionId,
        );
        assert.equal(invalidAgent, null);

        const validUser = parsePacket(
            encode({ ...base, type: 'user_state', state: 'listening' }),
            sessionId,
        );
        assert.ok(validUser);
        assert.equal(validUser.state, 'listening');

        const invalidUser = parsePacket(
            encode({ ...base, type: 'user_state', state: 'screaming' }),
            sessionId,
        );
        assert.equal(invalidUser, null);
    });

    it('validates user_partial, assistant_partial, and transcript text constraints', () => {
        const base = {
            v: 1,
            session_id: sessionId,
            seq: 3,
            sent_at: 1700000002,
            type: 'assistant_partial',
            id: 'c1b48b64e5284307a518fb99581cd2f4',
            text: 'Hello, welcome to Voca.',
            final: false,
        };

        assert.ok(parsePacket(encode(base), sessionId));

        // Text over 3000 chars rejected
        const oversizedText = 'a'.repeat(3001);
        assert.equal(parsePacket(encode({ ...base, text: oversizedText }), sessionId), null);

        // Invalid ID rejected
        assert.equal(parsePacket(encode({ ...base, id: 'short-id' }), sessionId), null);
        assert.equal(
            parsePacket(encode({ ...base, id: 'invalid!characters!in!this!id!here' }), sessionId),
            null,
        );

        // Non-boolean final rejected
        assert.equal(parsePacket(encode({ ...base, final: 'no' }), sessionId), null);

        // Transcript requires role, created_at, interrupted
        const transcriptPacket = {
            v: 1,
            session_id: sessionId,
            seq: 4,
            sent_at: 1700000003,
            type: 'transcript',
            id: 'c1b48b64e5284307a518fb99581cd2f4',
            role: 'assistant',
            text: 'How can I assist your business today?',
            final: true,
            interrupted: false,
            created_at: 1700000002.5,
        };
        assert.ok(parsePacket(encode(transcriptPacket), sessionId));

        // Invalid role rejected
        assert.equal(
            parsePacket(encode({ ...transcriptPacket, role: 'moderator' }), sessionId),
            null,
        );

        // Missing/invalid interrupted rejected
        assert.equal(
            parsePacket(encode({ ...transcriptPacket, interrupted: null }), sessionId),
            null,
        );
    });

    it('validates error packets and rejects unknown codes', () => {
        const base = { v: 1, session_id: sessionId, seq: 5, sent_at: 1700000004, type: 'error' };

        const known = parsePacket(encode({ ...base, code: 'provider_quota' }), sessionId);
        assert.ok(known);
        assert.equal(known.code, 'provider_quota');

        const unknown = parsePacket(encode({ ...base, code: 'system_meltdown' }), sessionId);
        assert.equal(unknown, null);
    });

    it('bounds and deduplicates transcripts safely up to 100 items', () => {
        let items = [];
        for (let i = 1; i <= 120; i++) {
            items = boundedTranscript(items, {
                id: `id-${String(i).padStart(32, '0')}`,
                role: i % 2 === 0 ? 'assistant' : 'user',
                text: `Turn ${i}`,
                interrupted: false,
                created_at: 1000 + i,
            });
        }
        assert.equal(items.length, 100);
        assert.equal(items[0].created_at, 1021);
        assert.equal(items[items.length - 1].created_at, 1120);

        // Updating an existing ID replaces it without creating duplicates
        const updated = boundedTranscript(items, {
            id: `id-${String(120).padStart(32, '0')}`,
            role: 'assistant',
            text: 'Turn 120 updated',
            interrupted: true,
            created_at: 1120,
        });
        assert.equal(updated.length, 100);
        assert.equal(updated[updated.length - 1].text, 'Turn 120 updated');
        assert.equal(updated[updated.length - 1].interrupted, true);
    });

    it('filters numeric metrics strictly and enforces upper bounds', () => {
        const input = {
            provider_first_audio_ms: 450,
            worker_turn_ms: 1200,
            input_tokens: 150,
            output_tokens: 80,
            turns: 3,
            interruptions: 1,
            invented_sla_metric: 9999,
            negative_latency: -10,
            too_large: 200000000,
            non_numeric: 'fast',
        };
        const sanitized = numericMetrics(input);
        assert.deepEqual(sanitized, {
            provider_first_audio_ms: 450,
            worker_turn_ms: 1200,
            input_tokens: 150,
            output_tokens: 80,
            turns: 3,
            interruptions: 1,
        });
    });

    it('maps error codes and HTTP statuses accurately', () => {
        assert.match(
            errorMessage('not_configured'),
            /LiveKit and the voice worker need installation/,
        );
        assert.match(errorMessage('provider_quota'), /OpenAI reported a quota/);
        assert.match(errorMessage('session_limit'), /session is already open/);
        assert.match(errorMessage('unknown_code', 401), /sign-in or security token expired/);
        assert.match(errorMessage('unknown_code', 403), /no longer have permission/);
        assert.match(errorMessage('unknown_code', 429), /Too many requests/);
    });

    it('maps WebRTC microphone exception names to user-friendly codes', () => {
        assert.equal(microphoneCode({ name: 'NotAllowedError' }), 'microphone_denied');
        assert.equal(microphoneCode({ name: 'PermissionDeniedError' }), 'microphone_denied');
        assert.equal(microphoneCode({ name: 'NotFoundError' }), 'microphone_missing');
        assert.equal(microphoneCode({ name: 'NotReadableError' }), 'microphone_busy');
        assert.equal(microphoneCode({ name: 'TrackStartError' }), 'microphone_busy');
        assert.equal(microphoneCode({ name: 'OtherError' }), 'connection_error');
    });

    it('identifies terminal session states', () => {
        assert.equal(isTerminal('ended'), true);
        assert.equal(isTerminal('failed'), true);
        assert.equal(isTerminal('expired'), true);
        assert.equal(isTerminal('revoked'), true);
        assert.equal(isTerminal('active'), false);
        assert.equal(isTerminal('provisioning'), false);
    });

    it('formats milliseconds honestly without zero-inflation', () => {
        assert.equal(formatMilliseconds(350.2), '350 ms');
        assert.equal(formatMilliseconds(null), 'Not measured');
        assert.equal(formatMilliseconds(undefined), 'Not measured');
        assert.equal(formatMilliseconds(-5), 'Not measured');
    });
});

describe('Browser RTC Performance Stats Extraction', () => {
    it('handles empty or null reports without throwing', () => {
        const result = audioPerformance([], null);
        assert.deepEqual(result.values, {});
        assert.equal(result.sender, null);
    });

    it('extracts selected candidate-pair RTT and inbound audio metrics', () => {
        const mockReport = new Map([
            [
                'pair-1',
                {
                    type: 'candidate-pair',
                    state: 'succeeded',
                    selected: true,
                    currentRoundTripTime: 0.045, // 45 ms
                },
            ],
            [
                'inbound-1',
                {
                    type: 'inbound-rtp',
                    kind: 'audio',
                    jitter: 0.008, // 8 ms
                    packetsLost: 2,
                    bytesReceived: 64000,
                    jitterBufferDelay: 0.24,
                    jitterBufferEmittedCount: 12,
                },
            ],
            [
                'outbound-1',
                {
                    type: 'outbound-rtp',
                    kind: 'audio',
                    bytesSent: 12000,
                    timestamp: 1000,
                },
            ],
        ]);

        const firstSample = audioPerformance([mockReport], null);
        assert.equal(Math.round(firstSample.values.rtt_ms), 45);
        assert.equal(Math.round(firstSample.values.jitter_ms), 8);
        assert.equal(firstSample.values.packets_lost, 2);
        assert.equal(firstSample.values.bytes_received, 64000);
        assert.equal(Math.round(firstSample.values.mean_buffer_ms), 20); // (0.24/12)*1000 = 20ms
        assert.equal(firstSample.values.uplink_kbps, undefined); // needs 2 samples
        assert.deepEqual(firstSample.sender, { bytes: 12000, timestamp: 1000 });

        // Second sample computes uplink bitrate
        const nextReport = new Map([
            [
                'outbound-1',
                {
                    type: 'outbound-rtp',
                    kind: 'audio',
                    bytesSent: 20000, // +8000 bytes = 64000 bits
                    timestamp: 2000, // +1000 ms = 1 sec
                },
            ],
        ]);
        const secondSample = audioPerformance([nextReport], firstSample.sender);
        assert.equal(Math.round(secondSample.values.uplink_kbps), 64); // 64 kbps
    });
});

describe('Voice Playground Alpine Controller Logic', () => {
    const mockOptions = {
        available: true,
        session_seconds: 900,
        agents: [
            { id: 1, name: 'Receptionist', language: 'en' },
            { id: 2, name: 'Karachi Support', language: 'ur' },
        ],
        choices: {
            provider: 'openai',
            catalog_id: 'cat-1',
            models: [
                {
                    id: 'gpt-4o-realtime-preview',
                    name: 'GPT-4o Realtime',
                    voice_ids: ['alloy', 'echo', 'shimmer'],
                },
            ],
            voices: [
                { id: 'alloy', name: 'Alloy' },
                { id: 'echo', name: 'Echo' },
                { id: 'shimmer', name: 'Shimmer' },
            ],
            languages: [
                { code: 'en', name: 'English' },
                { code: 'ur', name: 'Urdu' },
                { code: 'hi', name: 'Hindi' },
            ],
            default: {
                model: 'gpt-4o-realtime-preview',
                voice: 'alloy',
                language: 'en',
            },
        },
    };

    it('initializes defaults from options and enforces start prerequisites', () => {
        const ctrl = voicePlayground(mockOptions);
        ctrl.init();

        assert.equal(ctrl.agentId, '1');
        assert.equal(ctrl.model, 'gpt-4o-realtime-preview');
        assert.equal(ctrl.voice, 'alloy');
        assert.equal(ctrl.language, 'en');
        assert.equal(ctrl.consent, false);

        // Cannot start without consent
        assert.equal(ctrl.canStart, false);

        ctrl.consent = true;
        assert.equal(ctrl.canStart, true);

        // When status is active, canStart is false
        ctrl.status = 'active';
        assert.equal(ctrl.canStart, false);
    });

    it('initializes Google Gemini choices with prebuilt voices', () => {
        const geminiOptions = {
            available: true,
            session_seconds: 600,
            agents: [
                { id: 10, name: 'Gemini Assistant', language: 'en' },
            ],
            choices: {
                provider: 'Google Gemini',
                provider_id: 'gemini',
                models: [
                    {
                        id: 'gemini-3.8-live',
                        name: 'Gemini 3.8 Live',
                        voice_ids: ['Kore', 'Puck', 'Fenrir'],
                    },
                ],
                voices: [
                    { id: 'Kore', name: 'Kore' },
                    { id: 'Puck', name: 'Puck' },
                    { id: 'Fenrir', name: 'Fenrir' },
                ],
                languages: [
                    { code: 'en', name: 'English' },
                    { code: 'ur', name: 'Urdu' },
                ],
                default: {
                    model: 'gemini-3.8-live',
                    voice: 'Kore',
                    language: 'en',
                },
            },
        };

        const ctrl = voicePlayground(geminiOptions);
        ctrl.init();

        assert.equal(ctrl.model, 'gemini-3.8-live');
        assert.equal(ctrl.voice, 'Kore');
        assert.equal(ctrl.allowedVoices.length, 3);
        assert.equal(ctrl.allowedVoices[0].id, 'Kore');
    });

    it('updates language when agent changes if supported', () => {
        const ctrl = voicePlayground(mockOptions);
        ctrl.init();

        ctrl.agentId = '2';
        ctrl.agentChanged();
        assert.equal(ctrl.language, 'ur');
    });

    it('routes incoming room packets and manages ephemeral transcript', () => {
        const ctrl = voicePlayground(mockOptions);
        ctrl.init();
        ctrl.sessionId = 'test-session-uuid';

        function makePacket(type, data = {}) {
            return new TextEncoder().encode(
                JSON.stringify({
                    v: 1,
                    session_id: ctrl.sessionId,
                    seq: 1,
                    sent_at: 1000,
                    type,
                    ...data,
                }),
            );
        }

        // Agent state packet
        ctrl.handlePacket(makePacket('agent_state', { state: 'thinking' }));
        assert.equal(ctrl.agentState, 'thinking');

        // Assistant partial text preview
        ctrl.handlePacket(
            makePacket('assistant_partial', {
                id: 'c1b48b64e5284307a518fb99581cd2f4',
                text: 'I can help you with...',
                final: false,
            }),
        );
        assert.deepEqual(ctrl.assistantPartial, {
            id: 'c1b48b64e5284307a518fb99581cd2f4',
            text: 'I can help you with...',
        });

        // Final transcript turn
        ctrl.handlePacket(
            makePacket('transcript', {
                id: 'c1b48b64e5284307a518fb99581cd2f4',
                role: 'assistant',
                text: 'I can help you with booking an appointment.',
                final: true,
                interrupted: false,
                created_at: 1001,
            }),
        );
        assert.equal(ctrl.transcript.length, 1);
        assert.equal(ctrl.transcript[0].text, 'I can help you with booking an appointment.');
        // Assistant partial is cleared when final turn arrives
        assert.equal(ctrl.assistantPartial, null);

        // Worker metrics packet
        ctrl.handlePacket(
            makePacket('metrics', {
                values: {
                    provider_first_audio_ms: 320,
                    worker_turn_ms: 850,
                    turns: 1,
                },
            }),
        );
        assert.equal(ctrl.workerMetrics.provider_first_audio_ms, 320);
        assert.equal(ctrl.workerMetrics.worker_turn_ms, 850);
        assert.equal(ctrl.workerMetrics.turns, 1);

        // Interrupted packet triggers brief visual pulse
        ctrl.handlePacket(makePacket('interrupted'));
        assert.equal(ctrl.interrupted, true);
    });

    it('formats display values honestly without faking zeros', () => {
        const ctrl = voicePlayground(mockOptions);
        ctrl.init();

        assert.equal(ctrl.formatMs(null), 'Not measured');
        assert.equal(ctrl.formatMs(450), '450 ms');
        assert.equal(ctrl.formatKbps(null), 'Not measured');
        assert.equal(ctrl.formatKbps(64.2), '64 kbps');
        assert.equal(ctrl.formatHz(null), 'Not measured');
        assert.equal(ctrl.formatHz(48000), '48000 Hz');
        assert.equal(ctrl.formatTokens(null, null), 'Not measured');
        assert.equal(ctrl.formatTokens(150, 80), '150 in / 80 out');

        ctrl.remainingSeconds = 895;
        assert.equal(ctrl.formatCountdown(), '14:55');
    });
});
