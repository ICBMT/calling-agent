# Changelog

## 2026-11-10 — Milestone 3: Real-Time Browser Voice Engine

### Added

- **Laravel Control Plane (`app/Services/Voice/`):**
    - Session lifecycle management: `VoiceSessions`, `VoiceGateway`, `VoiceTokens`, `VoiceSelection`, `VoiceInfrastructure`, `VoiceWorkerAuth`.
    - Database migration `2026_11_10_000008_create_browser_voice_sessions.php` creating `voice_sessions` and `voice_worker_nonces` tables.
    - Per-actor unique active session slot (`active_user_key`) and organization concurrency limits (`organization_concurrency=3`).
    - Authority leases (`heartbeat_seconds=10`, `lease_seconds=25`) with fail-closed revocation on permission or configuration changes.
    - HMAC-SHA256 authenticated internal worker control routes (`/api/internal/voice-worker/claim`, `/heartbeat`, `/stop`) with single-use replay protection.
    - Automated session reaping and room deletion command (`php artisan voice:reap`) and `VoiceSessions::sweep()`.
- **Separate LiveKit Python Voice Worker (`voice-worker/`):**
    - Built with pinned `livekit-agents[openai]==1.8.11`.
    - Claims scoped credentials via HMAC control protocol; zero global API keys in worker configuration.
    - Strict privacy boundary: RoomIO text publishing disabled, custom `Captions` with `StreamRedactor` preventing credential prefix leaks, and ephemeral transcript streaming solely over room-scoped `voca.events` data packets.
    - Realtime turn detection and checked `voca.interrupt` RPC for instant barge-in cancellation.
    - 20-second reconnection grace period with `close_on_disconnect=False`.
    - Telemetry reporting: provider first-audio latency, worker turn duration, model/audio token counts, turns, and interruptions.
- **Browser Real-Time Voice Engine (`resources/js/workspace/voice/`):**
    - Lazy SDK loading: `livekit-client` (537 kB) code-split dynamically on demand, keeping main `app.js` bundle at ~78 kB.
    - `VoiceTransport`: On-demand microphone acquisition with echo cancellation and noise suppression, strict room name / participant identity binding, microphone-only publishing, expected-agent-only audio subscription, bounded reconnection with stale audio muting, and synchronous microphone teardown on session close (`microphone.stop()`).
    - `audioPerformance`: WebRTC `RTCStatsReport` extractor for candidate-pair RTT, inbound jitter, packet loss, jitter buffer delay, outbound bitrate delta (uplink kbps), and capture sample rate.
    - `voicePlayground` Alpine controller: Full POST/poll/DELETE lifecycle with generation guards, in-memory ephemeral transcript (bounded to 100 turns), explicit consent enforcement, mute/unmute, barge-in interrupt, speaker autoplay unlock, and honest metric formatting.
- **Voice Playground UI (`resources/views/workspace/playground.blade.php`, `resources/css/workspace.css`):**
    - Replaced local-only sound check screen with comprehensive native realtime session UI.
    - Prerequisites and blockers alert with direct links to Providers, Agents, and Setup.
    - Prior session cleanup action banner.
    - Realtime conversation controls, countdown timer, participant state chips, and ephemeral transcript stream.
    - Performance diagnostics panel separating local browser WebRTC stats from worker telemetry without faked zeros or invented SLAs.
    - Preserved `<x-microphone-check />` in an accessible collapsible details section for hardware testing.
- **Testing & Verification:**
    - Full PHP backend test suite: **218 tests / 1,730 assertions passing** (6.42s).
    - Python voice worker test suite: **84 tests passing** (1.64s), Ruff clean.
    - JavaScript unit test suite (`tests/Js/voice-protocol.test.js`): **18 tests passing** (894ms), Prettier clean.
    - Desktop & mobile Playwright browser test suite (`tests/Browser/playground.spec.js`): **18 tests passing** (2.1m).
    - CI workflow (`.github/workflows/ci.yml`) updated with JS unit tests and Python worker test/lint jobs.

### Verification Boundary

All tests pass locally with clean formatting, linting, and asset builds. No live credentials were supplied and external API hosts remain network-blocked in this sandbox. Genuine spoken browser voice acceptance requires a live LiveKit server and valid OpenAI Realtime key. Telephony and modular pipeline expansions remain strictly gated until a working browser voice conversation is accepted.

## 2026-11-10 — M2 final security/implementation review

- Added explicit actor/membership authorization to every server routing entry point and current persisted platform-operator checks; reauthorize late connection-test actors before applying results.
- Added generic no-store provider-route exception rendering/class-only reporting, disabled exception argument capture at bootstrap, and reinforced sensitive-parameter/DTO serialization protections.
- Added final normalized-metadata secret-reflection checks, safe failure-code allowlisting, strict catalog/date/voice/restriction validation, and conservative Gemini/ElevenLabs handling of malformed metadata.
- Removed officially shut-down `gpt-4.1-nano`; current documented maps/voice sets now revalidate cached selections. Preserved same-capability/scope explicit fail-closed routing.
- Preserved safe size-error classification through Laravel/Guzzle wrappers; corrected transport-option test instrumentation and exercised streaming guards.
- Added 45 adversarial review regressions; backend now **153 passing / 1,292 assertions**. Expanded desktop/mobile checks for response/browser-storage nonexposure. Added `SECURITY_REVIEW.md` and refreshed status/architecture/setup/testing/operations docs.
- Live-provider acceptance remains open: no real keys supplied, sandbox egress blocked, no authenticated account success, inference, audio, or calls verified. No later milestone started.

## 2026-11-10 — Milestone 2: secure provider configuration

### Added

- Encrypted hidden provider keys; organization-owned management, blank-preserving replacement, removal/deletion, version invalidation, stale-form checks, and redacted auditing/validation.
- Separately authorized optional shared platform-provider scope, explicit operator grant/revoke command, and no self-granted/seeded operator privileges.
- Genuine read-only OpenAI, Gemini, Deepgram, and ElevenLabs discovery adapters with documented authentication, account catalogs, exact dated modality mappings where necessary, and model/voice compatibility.
- Fixed trusted HTTPS routes/query parameters, TLS verification, blocked redirects/custom endpoints, secret-reflection guards, bounded time/response size/pagination, safe real failure categories, and no automatic retries/inference calls.
- Per-user/scope throttles, per-configuration locks, version/revision/run-token guards, bounded 20-check history, explicit selection-unavailable results, and 24-hour catalog expiry.
- Provider-independent native-realtime versus modular routing, unique scoped default/fallback designations, no silent incompatible substitution or credential-scope changes.
- Responsive AI providers, secure setup/management, documented comparison, real status/history, model/voice selection, optional actual account allowance/balance snapshots, and clear unavailable-usage states.
- 77 additional backend provider/security/adapter regressions (108 total / 892 assertions at local verification) and desktop/mobile provider browser scenarios; original foundation checks retained.
- Updated setup/health/homepage privacy statements and provider, architecture, database, installation, testing, and project-status documentation.

### Verification boundary

No real API keys were supplied. Actual sandbox requests to all four provider API hosts returned network failures with no HTTP response; there is no authenticated live success, audio generation, inference-permission verification, or phone call. HTTP-fake success tests are isolated automated protocol checks, not live acceptance. Full details and final browser results are in TESTING.md and PROJECT_STATUS.md.

## 2026-11-10 — Foundation milestone

### Added

- Laravel 13 application, locked dependencies, safe first-install command, and SQLite-friendly setup.
- Voca brand and original responsive public homepage with Cyber Grape / Acid Lime, self-hosted typography, glossy procedural 3D, GSAP/Lenis motion, and workflow storytelling.
- Functional use-case tabs, FAQ, motion preference, and local microphone lab with careful resource cleanup.
- Account registration, normalized-email sign-in, remember-device sessions, logout, throttling, CSRF protection, and basic security/cache headers.
- Organization memberships and owner/admin/member policies with tenant-scoped resources.
- Responsive workspace, actual database metrics/call views, useful empty states, and setup health/status journey.
- Persistent draft agents, local instruction templates, search, validation, soft deletion, business settings, and transactional audit records.
- 31 backend tests / 183 assertions and 14 desktop/mobile Chromium browser scenarios, including covered-view accessibility checks.
- Architecture, schema, installation, provider/voice plans, test/status documentation, and CI definitions.

### Intentionally not claimed

At the end of M1, no real AI call, telephony call, provider connection, generated transcript, latency result, usage billing, worker heartbeat, production deployment, or complete production-readiness acceptance test was claimed. M2 subsequently added the provider control plane with the separate live-acceptance limits above.
