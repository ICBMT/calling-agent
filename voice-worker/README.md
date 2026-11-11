# Voca Voice Worker (Milestone 3)

The Voca voice worker is a separate, stateless Python service that terminates continuous realtime WebRTC audio sessions via LiveKit and OpenAI Realtime. PHP controllers never carry streaming audio.

## Architecture & Security Boundaries

1. **No Global Provider Credentials:** The worker does not configure a global `OPENAI_API_KEY`. Each authorized room dispatch carries a short-lived single-use ticket. The worker authenticates with Laravel's control plane using an HMAC-SHA256 signature to claim a scoped session grant containing the decrypted M2 provider credentials.
2. **Authority Leases:** Sessions operate under an active lease (`heartbeat_seconds=10`, `lease_seconds=25`). If the workspace owner revokes access or the lease expires, the worker stops media playout and disconnects immediately.
3. **Privacy by Design:** Default RoomIO transcription publishing is disabled. A custom `Captions` output redacts sensitive credential prefixes (`StreamRedactor`) and publishes previews solely over `voca.events` data packets to the room participant. Audio and transcripts are never persisted to disk or server logs.
4. **Natural Interruption / Barge-in:** Realtime LLM turn detection and an explicit checked RPC (`voca.interrupt`) allow instant cancellation of obsolete speech, immediately truncating conversation items and muting stale audio.
5. **No Telephony in M3:** This worker handles browser WebRTC microphone → OpenAI Realtime → browser speaker. Telephony, SIP, and modular STT→LLM→TTS pipelines are strictly separated and not executable in this slice.

## Configuration

Copy `.env.example` to `.env` in this directory:

```bash
LIVEKIT_URL=wss://your-livekit-server.example.com
LIVEKIT_API_KEY=your-livekit-api-key
LIVEKIT_API_SECRET=your-livekit-api-secret
VOICE_WORKER_NAME=voca-browser-v1

VOCA_CONTROL_URL=https://voca.yourdomain.com
VOICE_WORKER_KEY_ID=v1
VOICE_WORKER_SECRET=your-shared-hmac-secret-min-32-chars
VOICE_ALLOW_INSECURE_LOCAL=false
```

## Local Testing & Verification

```bash
# Run unit and security regression tests (84 passing tests)
voice-worker/.venv/bin/python -m pytest voice-worker/tests -q

# Run Ruff linter
voice-worker/.venv/bin/ruff check voice-worker

# Verify CLI options
voice-worker/.venv/bin/python voice-worker/agent.py --help
```

## Running the Worker

```bash
# Development mode
voice-worker/.venv/bin/python voice-worker/agent.py dev

# Production daemon
voice-worker/.venv/bin/python voice-worker/agent.py start
```
