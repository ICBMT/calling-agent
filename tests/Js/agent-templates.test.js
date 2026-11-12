import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
    greetingFor,
    instructionsFor,
    presetPurposes,
} from '../../resources/js/workspace/agent-templates.js';

describe('Agent realism templates', () => {
    it('generates a purpose-aware, realism-focused instruction draft', () => {
        const text = instructionsFor({
            name: 'Maya',
            purpose: 'appointments',
            personality: 'empathetic',
        });

        assert.match(text, /You are Maya\./);
        assert.match(text, /Patient, gentle and reassuring/);
        assert.match(text, /book, reschedule or cancel appointments/);
        // Realism anchors every draft must carry
        assert.match(text, /one or two short sentences/);
        assert.match(text, /one question at a time/);
        assert.match(text, /read it back slowly/);
        assert.match(text, /never use markdown/i);
        assert.match(text, /Never invent prices, availability, policies/);
        assert.match(text, /AI assistant/);
        // Server limit: instructions max 12,000 chars
        assert.ok(text.length >= 20 && text.length <= 12000);
    });

    it('gives all four preset purposes distinct playbooks', () => {
        const bodies = presetPurposes.map((purpose) => instructionsFor({ purpose }));
        assert.equal(new Set(bodies).size, presetPurposes.length);
        for (const purpose of presetPurposes) {
            assert.ok(instructionsFor({ purpose }).length > 400);
        }
    });

    it('falls back safely for unknown purpose and personality values', () => {
        const text = instructionsFor({ purpose: 'hacker', personality: 'unknown' });
        assert.match(text, /a business assistant/);
        assert.match(text, /Warm, upbeat and personable/);
    });

    it('suggests short spoken-style greetings within provider limits', () => {
        const greeting = greetingFor({ name: 'Maya', purpose: 'receptionist' });
        assert.match(greeting, /Maya/);
        assert.match(greeting, /AI assistant/);
        assert.ok(greeting.length <= 500);
        assert.ok(!greeting.includes('\n'), 'greeting stays one speakable paragraph');

        // Unknown purpose falls back, empty names never leave a blank speaker.
        const fallback = greetingFor({ name: '  ', purpose: 'custom' });
        assert.match(fallback, /your assistant/);
    });

    it('never embeds unvalidated caller-controlled data beyond name bounds', () => {
        const hostile = 'X'.repeat(500);
        const text = instructionsFor({ name: hostile, purpose: 'support' });
        assert.ok(text.length <= 12000);
        assert.ok(!text.includes(hostile), 'name is truncated to a sane bound');
    });
});
