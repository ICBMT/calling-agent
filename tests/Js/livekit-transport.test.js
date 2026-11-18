import test from 'node:test';
import assert from 'node:assert/strict';
import { VoiceTransport } from '../../resources/js/workspace/voice/livekit-transport.js';

test('LiveKit transport retries cancellation when RPC routing lags behind room recovery', async () => {
    const transport = Object.create(VoiceTransport.prototype);
    transport.closed = false;
    let attempts = 0;
    transport.interrupt = async () => {
        attempts += 1;
        if (attempts === 1) throw new Error('temporary RPC route gap');
    };

    await transport.interruptAfterReconnect();

    assert.equal(attempts, 2);
});

test('LiveKit transport fails closed when reconnect cancellation is never confirmed', async () => {
    const transport = Object.create(VoiceTransport.prototype);
    transport.closed = false;
    let attempts = 0;
    transport.interrupt = async () => {
        attempts += 1;
        throw new Error('agent control route unavailable');
    };

    await assert.rejects(transport.interruptAfterReconnect(), /Cancellation after reconnect/);
    assert.equal(attempts, 2);
});
