# Voice Engine (Milestone 3)

## Overview

Voca’s voice engine delivers production-grade, two-way streamed browser voice over WebRTC with turn detection, natural interruptions (barge-in), and live ephemeral captions. PHP controllers never carry streaming media; continuous audio is handled by a separate LiveKit Python worker.

```text
Browser Microphone ──WebRTC Audio──> LiveKit Room <──WebRTC Audio── LiveKit Python Worker
        │                                                           │
        │ <──voca.events Data Packets (captions, telemetry)──────── │
        │ ───voca.interrupt RPC (barge-in cancellation)───────────> │
        │                                                           │
Browser Speaker <────WebRTC Audio───────────────────────────────────┼──> OpenAI Realtime (S2S)
                                                                    │
                                                              Control Plane (HMAC-SHA256)
                                                                    │
                                                                    v
                                                             Laravel (Post / Claim / Heartbeat)
```

## Milestone 3 Delivery & Architecture

### 1. Laravel Control Plane

- **Service Layer:** `app/Services/Voice/`
    - `VoiceInfrastructure`: Validates LiveKit URL and credentials; enforces HTTPS / WSS schemes.
    - `VoiceGateway`: Manages LiveKit rooms and dispatches jobs to the named worker pool via LiveKit Server SDK / twirp APIs.
    - `VoiceTokens`: Issues short-lived browser and worker JWTs scoped to exact room name, identity, and permissions (browser publishes audio only, worker subscribes and publishes audio + data).
    - `VoiceSelection`: Intersects M2 encrypted provider configurations, ensures fresh catalogs (within 24h), validates model/voice/language compatibility, and snapshots instructions.
    - `VoiceSessions`: Manages session lifecycle (`provisioning` → `dispatched` → `starting` → `active` → `ended`/`failed`/`expired`/`revoked`).
    - `VoiceWorkerAuth`: Validates HMAC-SHA256 signed requests from the worker using shared secrets, timestamps, and single-use nonces (`voice_worker_nonces`).
- **Database Schema:** `database/migrations/2026_11_10_000008_create_browser_voice_sessions.php`
    - `voice_sessions`: Tracks session UUID, tenant ID, user ID, agent ID, credential version, configuration snapshot, room name, participant/worker identities, dispatch ID, lease hashes, claim timestamps, heartbeat timestamps, error codes, and bounded numeric metrics.
    - `voice_worker_nonces`: Single-use replay protection for worker requests.
- **Concurrency & Quotas:**
    - `active_user_key`: Unique constraint enforces one active session per user per tenant; terminal sessions release the unique slot.
    - `organization_concurrency`: Limits concurrent active sessions per workspace (default 3).
- **Authority Leases:**
    - Sessions operate under an active lease (`heartbeat_seconds=10`, `lease_seconds=25`).
    - If a workspace user revokes access, modifies agent instructions, deletes credentials, or if the worker fails to heartbeat, the lease fails closed and the session is terminated.
- **Reaping & Housekeeping:**
    - `php artisan voice:reap` and `VoiceSessions::sweep()` run periodically to reap expired sessions and clean up orphaned LiveKit rooms.

### 2. Separate LiveKit Python Voice Worker

Located in `voice-worker/`:

- **Independent Stateless Worker:** Built with `livekit-agents[openai]==1.8.11`, Python 3.11+.
- **No Global Credentials:** The worker does not configure a global `OPENAI_API_KEY`. Each job claims a single-use dispatch ticket from Laravel over HMAC-SHA256, obtaining the decrypted M2 provider key only for that job's duration.
- **Strict Privacy Boundary:**
    - Default LiveKit RoomIO text publishing is disabled.
    - Custom `Captions` class redacts credential prefixes (`StreamRedactor`) and publishes previews solely over `voca.events` data packets directly to the user's participant identity.
    - Audio and transcripts are never written to disk or server logs.
- **Turn Detection & Interruption:**
    - Realtime LLM turn detection handles natural pauses.
    - `voca.interrupt` RPC allows immediate client-side barge-in, truncating conversation items and cancelling in-flight provider responses within 3 seconds.
- **Reconnection Handling:**
    - `room_options(close_on_disconnect=False)` preserves a 20-second grace window in `VoiceRuntime`. If a browser drops network packets and reconnects, prior audio is silenced and conversation resumes cleanly.

### 3. Browser Transport & Performance

- **Lazy SDK Loading:** `livekit-client` (537 kB) is dynamically loaded on demand when the voice playground is mounted/started, keeping the main app bundle at ~78 kB.
- **VoiceTransport Wrapper:** (`resources/js/workspace/voice/livekit-transport.js`)
    - Acquires microphone track on demand with echo cancellation, noise suppression, and auto gain control.
    - Enforces room name and participant identity binding on connection.
    - Publishes microphone track only (`Track.Source.Microphone`).
    - Subscribes only to audio tracks from the verified `agent_identity`.
    - Mutes remote audio element immediately during reconnects or interrupts to prevent stale audio replay.
    - Synchronous privacy boundary: `microphone.stop()` is called immediately on session closure.
- **Measured WebRTC Statistics:** (`resources/js/workspace/voice/performance.js`)
    - Extracts local browser `RTCStatsReport` metrics: selected candidate-pair RTT, inbound audio jitter, packets lost, jitter buffer delay, outbound audio bitrate delta (uplink kbps), and capture sample rate.
    - Explicitly labelled as browser WebRTC audio stats; never confused with provider inference latency or human ear performance.

### 4. Honest Voice Playground UI

Located in `resources/views/workspace/playground.blade.php` and `resources/js/workspace/voice/playground.js`:

- **Configuration & Guards:**
    - Agent draft selection, realtime model selection, voice identity selection, and language selection (Urdu, English, Hindi).
    - Explicit consent checkbox required before starting.
    - Bounded generation tokens (`generation++`) ensure late network responses or callbacks from aborted sessions cannot leak into a fresh session.
    - Reconnect warnings and playback unlock button (`Enable speaker`) if browser blocks autoplay.
- **Live Controls:**
    - Toggle microphone mute (`Mute mic` / `Unmute mic`).
    - Interrupt button (`Interrupt AI` / Barge-in) calling `voca.interrupt` RPC.
    - Danger `End session` button tearing down WebRTC and notifying backend.
- **Live Ephemeral Transcript:**
    - In-memory only; bounded to 100 turns.
    - Displays user and AI turns with distinct styling, streaming partial text previews, and interruption indicators.
- **Honest Performance Diagnostics:**
    - Separates local WebRTC measurements from worker/provider telemetry.
    - Missing metrics display "Not measured", never zero or an invented SLA.
- **Local Sound Check:**
    - Preserves `<x-microphone-check />` in an accessible collapsible details section for zero-cost hardware checks.

## Acceptance Gates

### Browser Voice Acceptance Gate (Current Gate)

Before claiming live readiness:

1. LiveKit server and separate voice worker must be deployed and reachable.
2. An authentic OpenAI Realtime credential must be configured and tested in Providers.
3. A real human microphone → LiveKit worker → OpenAI Realtime → browser speaker conversation must be accepted with natural speech.

Automated tests in this repository use HTTP fixtures, synthetic Chromium media streams, and negative network checks; they verify contracts, lifecycle, security, and UI resilience, but **do not constitute live spoken acceptance**.

### Telephony Gate (Strictly Blocked)

No telephone calling (Twilio, SIP, inbound/outbound) or additional media providers will begin until reliable natural browser voice is genuinely accepted.
