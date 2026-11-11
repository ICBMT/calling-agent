# Project status — Voca

**Updated:** November 10, 2026  
**Release:** Foundation (M1) + Milestone 2 Secure Provider Control Plane + Milestone 3 Real-Time Browser Voice Engine + Milestone 4 Telephony Media Gateway Foundation & Production Deployment System  
**Overall:** M1, M2, and M3 locally approved. **Production deployment system, automated installation/upgrade tooling, and Milestone 4 telephony gateway delivered and verified locally.** Live-service acceptance with external carriers/SIP trunks remains gated on production infrastructure credentials.

---

## Milestone 4 & Production Deployment System — Delivered

### 1. Operational Diagnostics (`php artisan voca:doctor`)

- Added command `app/Console/Commands/DoctorVoca.php` providing 12 diagnostic checks:
    1. PHP runtime version (>= 8.3) and required extensions (`pdo_sqlite`, `mbstring`, `openssl`, `curl`, `json`, `bcmath`, `fileinfo`).
    2. Composer production dependencies (`vendor/autoload.php`).
    3. Vite frontend build manifest (`public/build/manifest.json`).
    4. Python 3.11+ virtual environment (`voice-worker/.venv`).
    5. Python worker dependencies (`livekit-agents` and provider plugins).
    6. Database connectivity & storage directory permissions.
    7. Database migration status (pending migrations detection).
    8. Laravel encryption key (`APP_KEY`).
    9. Queue worker heartbeat cache state.
    10. LiveKit server connectivity via Twirp `/twirp/livekit.RoomService/ListRooms`.
    11. Voice worker LiveKit registration health probe (port 8081).
    12. Configured AI providers freshness & production security checks (`APP_DEBUG`, `SESSION_SECURE_COOKIE`).
- CLI table output with clear PASS/WARN/FAIL status badges and concrete remediation steps.
- Machine-readable `--json` flag for integration into CI/CD pipelines, container health checks, and Datadog/Zabbix monitoring.

### 2. Automated Upgrades (`php artisan voca:upgrade` & `bin/upgrade.sh`)

- Automated, non-destructive upgrade sequence:
    1. Automatic timestamped SQLite backup in `storage/backups/`.
    2. Non-destructive migration runner (`php artisan migrate --force`).
    3. Safe application cache clearing (`optimize:clear`).
    4. Frontend asset rebuild.
    5. Automatic worker restart.
    6. Post-upgrade diagnostics verification via `voca:doctor`.

### 3. Production Deployment Infrastructure

- Systemd service units in `deploy/systemd/`:
    - `voca-web.service`: Persistent web application server with systemd sandbox hardening.
    - `voca-queue.service`: Background queue worker with automatic restart on failure.
    - `voca-voice-worker.service`: Dedicated Python voice worker process.
    - `livekit.service`: Self-hosted LiveKit media server unit.
- Production Nginx reverse proxy configuration in `deploy/nginx/voca.conf`:
    - TLS configuration and modern cipher suites.
    - LiveKit WebSocket signaling and RTC proxying.
    - Security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options).
    - Strict protection of dotfiles, secrets, and private directories.
- One-command automated installation script (`bin/install.sh`) for Ubuntu LTS fresh servers.
- One-command maintenance upgrade script (`bin/upgrade.sh`).
- Comprehensive operations documentation: `DEPLOYMENT.md` and `TROUBLESHOOTING.md`.

### 4. Telephony & SIP Gateway Foundation (Milestone 4)

- Configuration in `config/telephony.php` supporting Twilio, Telnyx, and LiveKit SIP Participant gateways.
- `TelephonyGateway` service (`app/Services/Telephony/TelephonyGateway.php`):
    - Cryptographic webhook signature verification (HMAC-SHA1 for Twilio, ED25519 for Telnyx).
    - Inbound call dispatching to LiveKit SIP media rooms with TwiML generation.
    - Status callback handling with automatic duration tracking and call completion status.
- Dedicated telephony routes in `routes/telephony.php` (`POST /api/telephony/inbound/{provider}`, `POST /api/telephony/status/{provider}`).
- Integrated into `SystemHealth` diagnostics (`Telephony: Configured` / `Not configured`).

---

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

- Lazy SDK loading: `livekit-client` dynamically loaded on demand, keeping main `app.js` bundle minimal (~78 kB).
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

---

## Verification Completed Locally

| Check                             | Verified Result                          | Notes                                           |
| --------------------------------- | ---------------------------------------- | ----------------------------------------------- |
| Full PHP backend suite            | **237 tests / 1,792 assertions passing** | Unit, Feature, Isolation, Console, Telephony    |
| Python voice worker suite         | **86 tests passing** (2.60s), Ruff clean | Unit, Security, Grant, Leases, Metrics, Privacy |
| JavaScript unit suite             | **18 tests passing**                     | Protocol, RTC Stats, Controller Logic           |
| Diagnostics check (`voca:doctor`) | **PASS** across 12 diagnostic areas      | Verifies CLI table & JSON mode                  |
| Code style & linting              | **Pint, Prettier, and Ruff passing**     | All files cleanly formatted                     |
| Production asset build            | **Vite build passing**                   | Code-split LiveKit transport chunk              |
| SQLite migration cycle            | **Rollback and re-apply verified**       | Clean down/up for all migrations                |

---

## Acceptance Boundary & Operational Next Steps

- **Live Provider Testing**: While all unit, integration, and feature tests pass in the sandbox, live browser-to-agent audio requires external provider API keys (OpenAI Realtime / Google Gemini Live) and an active LiveKit server URL configured in `.env`.
- **Telephony Production Setup**: To accept real inbound phone calls over PSTN, configure a Twilio or Telnyx number and point its Voice Webhook URL to `https://your-voca-domain.com/api/telephony/inbound/twilio`.
