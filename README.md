# Voca

**Make every hello count.** An AI calling platform, built in verified stages on Laravel 13.

Voca pairs a Cyber Grape / Acid Lime identity with a glossy Three.js voice sculpture, GSAP scroll storytelling, liquid-glass details, and a calm, functional business workspace. The visual direction takes inspiration from the energy and editorial pacing of the reference in the project brief; no reference-site assets are copied.

> **Foundation + secure providers + realtime browser voice engine, not a production telephone service.** Accounts, isolated workspaces, draft agents, settings, call views, encrypted provider configurations, and the real-time browser voice engine with separate LiveKit Python worker work locally now. Telephone calling, SIP, knowledge, campaigns, and billing are not implemented. No live provider key or LiveKit server has been connected in this sandbox. No connection success or call activity is fabricated.

## Quick start

Requires **PHP 8.3+**, Composer 2, **Node 22.12+**, npm, **Python 3.11+** (for the voice worker), and the extensions described in [INSTALLATION.md](INSTALLATION.md). PHP 8.4 is the verified development runtime.

From the repository root:

```sh
composer run setup
php artisan serve --host=0.0.0.0 --port=8000
```

`setup` installs the locked dependencies, creates `.env` if missing, creates a local SQLite database, generates an application key **only if none exists**, runs migrations, and builds the frontend. It does not seed a shared login, demo calls, or test transcripts. Open the application and create your own account.

**Full guided setup (LiveKit, voice worker, four dev processes, first browser call):** follow the
numbered terminal guide in [COMMAND_MANUAL.md](COMMAND_MANUAL.md). For a production server, run
`sudo bash bin/install.sh` instead. Verify any installation with `php artisan voca:doctor`.

The built assets are served by Laravel; a separate Vite server is not needed for this preview. For frontend development, use `composer run dev`.

## What works

- Original responsive public homepage with procedural 3D, GSAP, Lenis, interactive use cases, and native FAQ disclosure controls.
- Reduced-motion detection, a persistent motion toggle, and a CSS fallback without WebGL.
- Register, sign in, remember-device authentication, and sign out.
- An isolated organization and owner membership created with every account.
- Owner/admin mutation permissions and member read-only access, enforced on the server.
- Create, edit, search, and soft-delete draft agents, with personality, purpose, language preference, instructions, greeting, a local starter-template generator, and one-click realism presets (Receptionist, Support, Appointments, Sales) that fill proven call-handling instructions and a spoken opening line.
- Guided first-call journey on the Overview: agent → verified provider → voice engine online → live playground call, backed by genuine health checks.
- Conversation realism engine in the voice worker: spoken-only style rules, single-question turns, read-back confirmation of captured details, interruption abandonment, and a full open-to-close call flow, rendered per-agent through personality-specific tone guidance.
- Business name, timezone, and default draft-language settings.
- Actual database metrics and filtered call history. A new workspace starts empty.
- Encrypted workspace provider keys for OpenAI, Google Gemini, Deepgram, and ElevenLabs, plus separately authorized optional platform defaults.
- Genuine read-only Test Connection, safe real error categories/HTTP status, versioned bounded history, and capability-compatible model/voice selections.
- Explicit same-category/scope default/fallback resolution, stale catalog/key-rotation protection, setup/comparison pages, and real allowance/balance snapshots or clear usage unavailability.
- Real-time browser voice engine (Milestone 3):
    - Laravel control plane: room dispatch, scoped JWT tokens, authority leases, HMAC-SHA256 worker authentication, session lifecycle, and automatic reaping.
    - Dedicated Python worker (`voice-worker/`): LiveKit WebRTC termination, per-job credential claims, zero global API keys, custom sanitized captions (`StreamRedactor`), realtime turn detection, checked `voca.interrupt` RPC for barge-in, and 20s reconnect grace.
    - Native browser transport: lazy LiveKit SDK chunk loading, on-demand microphone acquisition, strict room/participant binding, ephemeral in-memory transcript (never logged to disk), mute/interrupt/speaker controls, and live WebRTC RTCStats extraction.
    - Honest voice playground UI (`/app/playground`): explicit prerequisites check, consent enforcement, live conversation controls, and side-by-side local WebRTC stats versus worker telemetry with no faked zeros or invented SLAs.
- Local browser microphone waveform check (`<x-microphone-check />`) preserved for zero-cost hardware diagnostics. Audio is not recorded or uploaded; tracks are released when stopped, closed, hidden, or navigated away from.
- Transactional audit records for workspace, agents, settings, provider changes/checks, and explicit platform-operator provisioning, without request bodies or credentials.

## Verify

```sh
# Full PHP backend test suite (241 tests / 1,806 assertions)
php artisan test                    # native PHP
npm run test:php:wasm               # sandboxed machines: real PHP in WebAssembly
vendor/bin/pint --test

# Python voice worker suite (88 tests, Ruff clean)
voice-worker/.venv/bin/python -m pytest voice-worker/tests -q
voice-worker/.venv/bin/ruff check voice-worker

# JavaScript unit suite (29 tests, Prettier clean)
npm run test:unit
npm run format:check

# Sandboxed UI verification (headless Chromium, all assets from npm)
npm run serve:wasm &                # serve the real app on :8000 via WASM PHP
npm run ui:sweep                    # routes: status + console errors + screenshots
npm run ui:flow                     # full register→workspace flow with screenshots


# Frontend production build
npm run build

# Playwright browser suite (18 tests: desktop & mobile Chromium)
npx playwright install chromium
npm run test:browser
```

The browser suite uses a separate ignored SQLite database. It checks desktop and mobile-emulated Chromium, accessibility, CSRF protection, complete draft/provider CRUD workflows, genuine negative provider checks, stored-secret redaction, and synthetic-device microphone lifecycle handling. HTTP-fake success cases are isolated backend tests, not live acceptance. Browser negative checks exercise the actual transport with intentionally invalid sentinels; no authenticated provider success, AI audio, or telephone integration is claimed. See [TESTING.md](TESTING.md).

## Documentation

| Document                                 | Contents                                                              |
| ---------------------------------------- | --------------------------------------------------------------------- |
| [PROJECT_STATUS.md](PROJECT_STATUS.md)   | Verified features, limitations, next milestone                        |
| [ARCHITECTURE.md](ARCHITECTURE.md)       | Application boundaries, directory plan, risks, development stages     |
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | Current tables, relationships, authorization, planned schema          |
| [INSTALLATION.md](INSTALLATION.md)       | Local setup, environment, server guidance, backup, troubleshooting    |
| [PROVIDER_SETUP.md](PROVIDER_SETUP.md)   | Supported provider setup, security, status, live acceptance           |
| [VOICE_ENGINE.md](VOICE_ENGINE.md)       | Current microphone check and future LiveKit architecture              |
| [TESTING.md](TESTING.md)                 | Commands, coverage, verified results, untested acceptance gates       |
| [SECURITY_REVIEW.md](SECURITY_REVIEW.md) | M2 review, findings fixed, five guarantees and operational boundaries |
| [CHANGELOG.md](CHANGELOG.md)             | Changes by milestone                                                  |

The full product brief remains in [callingapplicationinstruction.txt](callingapplicationinstruction.txt).

## Provider setup and next milestone

Sign in as an owner/admin and open **AI providers** (`/app/providers`). Keys are entered through the encrypted UI, not environment variables or agent prompts. See [PROVIDER_SETUP.md](PROVIDER_SETUP.md) for account scopes, optional platform operators, real connection checks, and the still-open real-key acceptance gate.

Next: validated/versioned agent deployments and a separate LiveKit worker for genuine browser conversations. A successful metadata check must never be presented as a successful voice call.

**Do not deploy this foundation as a finished AI calling product.** Follow the staged acceptance gates in the architecture and status documents.
