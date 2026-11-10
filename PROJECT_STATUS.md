# Project status — Voca

**Updated:** November 10, 2026  
**Release:** Foundation (M1) + Milestone 2 secure provider control plane + Milestone 3 Real-Time Browser Voice Engine  
**Overall:** M1 and M2 locally approved. **M3 real-time browser voice engine delivered and verified locally; live-service spoken acceptance remains blocked on external credentials.** Not a completed or production-ready AI calling platform.

## Milestone 3 — Delivered

### 1. Laravel Control Plane & Session Lifecycle

- Migration `2026_11_10_000008_create_browser_voice_sessions.php` establishes `voice_sessions` and single-use `voice_worker_nonces` tables.
- Services in `app/Services/Voice/`:
    - `VoiceInfrastructure`: Validates LiveKit URL, credentials, and schemes.
    - `VoiceGateway`: Room creation and job dispatching to worker pool.
    - `VoiceTokens`: Scoped JWT minting for browser and worker.
    - `VoiceSelection`: Intersects M2 encrypted provider configs, ensures fresh catalogs, validates model/voice/language choices.
    - `VoiceSessions`: Full session lifecycle management, unique active slot per user (`active_user_key`), tenant concurrency limit (`organization_concurrency=3`).
    - `VoiceWorkerAuth`: HMAC-SHA256 signature verification with timestamps and single-use replay protection.
- Authority leases (`heartbeat_seconds=10`, `lease_seconds=25`): fail-closed revocation when credentials, agent instructions, or worker heartbeats lapse.
- Periodic cleanup via `VoiceSessions::sweep()` and `php artisan voice:reap`.

### 2. Separate LiveKit Python Voice Worker

- Dedicated service in `voice-worker/` with pinned `livekit-agents[openai]==1.8.11`.
- Per-job HMAC-SHA256 authenticated control protocol (`/api/internal/voice-worker/claim`, `/heartbeat`, `/stop`). No global provider API keys in worker configuration.
- Strict privacy boundary: RoomIO text publishing disabled, custom `Captions` with `StreamRedactor` prevents credential prefix leaks, transcripts streamed solely via room-scoped `voca.events` data packets.
- Realtime turn detection and checked `voca.interrupt` RPC for instant barge-in cancellation.
- 20-second reconnection grace window with `close_on_disconnect=False`.
- Telemetry measurements for latency and usage (`provider_first_audio_ms`, `worker_turn_ms`, tokens, turns, interruptions).

### 3. Browser Voice Engine & Performance

- Lazy SDK loading: `livekit-client` (537 kB) dynamically loaded on demand, keeping main `app.js` bundle at ~78 kB.
- `VoiceTransport` wrapper (`resources/js/workspace/voice/livekit-transport.js`):
    - On-demand microphone acquisition with echo cancellation, noise suppression, and auto gain control.
    - Strict room name and participant identity binding.
    - Publishes microphone only; subscribes only to expected `agent_identity` audio.
    - Reconnection state handling with stale audio muting.
    - Synchronous microphone teardown on session close (`microphone.stop()`).
- Measured RTC performance (`resources/js/workspace/voice/performance.js`):
    - Extracts RTT, jitter, packet loss, jitter buffer delay, uplink bitrate delta, and capture sample rate from local `RTCStatsReport`.
    - Strictly labelled as browser WebRTC audio stats; never confused with provider inference latency.

### 4. Honest Voice Playground UI

- Complete rewrite of `resources/views/workspace/playground.blade.php` and Alpine controller `resources/js/workspace/voice/playground.js`.
- Session configuration: draft agent selection, realtime model, voice identity, and spoken language (Urdu, English, Hindi).
- Explicit consent checkbox required before starting.
- Live controls: mute/unmute microphone, interrupt AI, enable speaker autoplay unlock, end session.
- In-memory ephemeral transcript (bounded to 100 turns, streaming partial speech previews, interruption tags).
- Honest diagnostics: separates local WebRTC stats from worker telemetry. Missing measurements display "Not measured", never zero or an invented SLA.
- Local hardware check: preserves `<x-microphone-check />` for zero-cost microphone checks.

## Verification Completed Locally

| Check                             | Verified Result                                  | Notes                                           |
| --------------------------------- | ------------------------------------------------ | ----------------------------------------------- |
| Full PHP backend suite            | **218 tests / 1,730 assertions passing** (6.42s) | Unit, Feature, Isolation, M2 & M3 regressions   |
| Python voice worker suite         | **84 tests passing** (1.64s), Ruff clean         | Unit, Security, Grant, Leases, Metrics, Privacy |
| JavaScript unit suite             | **18 tests passing** (894ms), Prettier clean     | Protocol, RTC Stats, Controller Logic           |
| Desktop & mobile Playwright suite | **18 tests passing** (2.1m)                      | Foundation, Providers, Voice Playground         |
| Code style & linting              | **Pint, Prettier, and Ruff passing**             | All files cleanly formatted                     |
| Production asset build            | **Vite build passing**                           | Code-split LiveKit transport chunk              |
| SQLite migration cycle            | **Rollback and re-apply verified**               | Clean down/up for migrations 000007 & 000008    |

## Live Acceptance Gate (Current Boundary)

**No live-readiness claim:** While the control plane, worker, transport, and UI are fully built, formatted, and locally tested, sandbox outbound rules block external provider hosts and no LiveKit server or OpenAI Realtime keys have been supplied. Authenticated spoken acceptance requires deploying reachable LiveKit infrastructure and providing an active OpenAI Realtime key through the secure UI.

**Strict Telephony Gate:** No telephone calling (Twilio, SIP, inbound/outbound) or additional media providers will begin until a genuine human microphone → LiveKit worker → OpenAI Realtime → browser speaker conversation is accepted.
