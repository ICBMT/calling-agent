# Testing and verification

## Verified environment

- Laravel **13.40.0**, PHP **8.4.27**, Composer **2.10.3**.
- Node **22.22.3**, npm **10.9.8**, SQLite.
- Playwright Chromium: desktop 1440 × 1000 and iPhone-size mobile emulation.
- No real provider keys supplied. No LiveKit server, voice worker, telephony service, or Redis service tested.

The lock's PHP requirements are compatible with PHP 8.3, but executing on other PHP versions and MySQL remains a separate gate. CI is included, **not claimed remotely run**.

## Latest local results — November 10, 2026

| Check                                                              | Result                                                       |
| ------------------------------------------------------------------ | ------------------------------------------------------------ |
| Full PHP suite                                                     | **218 passed / 1,730 assertions** (6.42s)                    |
| Python voice worker suite                                          | **84 passed** (1.64s), Ruff clean                            |
| JavaScript unit suite (`node --test tests/Js`)                     | **18 passed** (894ms), Prettier clean                        |
| Full desktop/mobile Chromium suite                                 | **18 passed** (2.1m)                                         |
| Covered-view Axe WCAG 2 A/AA and 2.1 AA, including contrast        | Passed within the browser suite                              |
| PHP formatting (112 files) / Composer manifest / Blade compilation | Passed locally                                               |
| SQLite migrations / production frontend build                      | Passed locally                                               |
| Prettier source/docs formatting                                    | Checked locally                                              |
| npm advisory audit                                                 | **0 reported vulnerabilities** at verification time          |
| Authenticated live provider success                                | **Not verified — no real keys; provider API egress blocked** |
| Generated AI audio / inference entitlement / answered phone call   | **Not implemented or tested**                                |

## Backend commands and coverage

```sh
# PHP backend test suite
php artisan test
vendor/bin/pint --test
composer validate --no-check-publish

# Python voice worker suite
voice-worker/.venv/bin/python -m pytest voice-worker/tests -q
voice-worker/.venv/bin/ruff check voice-worker

# JavaScript unit suite
npm run test:unit
npm run format:check
```

The suite retains all foundation and M2 coverage, and adds **65 M3 voice engine regressions** across:

- `VoiceSessionTest`: full lifecycle transitions (`provisioning` → `dispatched` → `starting` → `active` → `ended`/`failed`/`expired`/`revoked`), store/show/destroy API endpoints, strict tenant isolation and role authorization, active user key database uniqueness, organization concurrency limits (default 3), explicit consent enforcement, transient instruction snapshots, and automated reaping.
- `VoiceWorkerSecurityTest`: HMAC-SHA256 signature verification, replay protection with single-use nonces (`voice_worker_nonces`), authority leases, heartbeat timeout, access revocation, prompt snapshot erasing, safe metric allowlist, and fail-closed room cleanup.

### Python Voice Worker Coverage (`voice-worker/tests/`)

- 84 unit and security regression tests covering grant contracts, HMAC control client, privacy sanitizer, `StreamRedactor`, custom `Captions`, native session factory, measured telemetry, authority lease timing, runtime lifecycle, and `agent.py` CLI help.

### JavaScript Unit Coverage (`tests/Js/voice-protocol.test.js`)

- 18 unit tests covering room data packet parsing, bounded transcript deduplication (up to 100 turns), strict metric allowlist filtering, error code and WebRTC exception mapping, local browser `RTCStatsReport` extraction (candidate-pair RTT, jitter, loss, buffer delay, uplink bitrate), and Alpine playground controller logic.

Database feature tests use isolated in-memory SQLite with `RefreshDatabase`. Fixtures create data **only in tests**; the normal seeder is intentionally empty.

- `ProviderConfigurationTest`: actual ciphertext versus plaintext, hidden JSON/debug/view values, never-flashed key input, key replacement/removal/deletion, missing keys, stale forms, prohibited status/tenant/endpoint/catalog injection, owner/admin/member rights, foreign IDs, fresh catalog selection, invalid model/voice pairs, expiry, explicit unique default/fallback routing, and zero-supported-model honesty.
- `ProviderConnectionTest`: 401/403/404/429/5xx, invalid credentials versus denied permissions, real quota categories, Google error-info restrictions, unexpected/non-200 success schemas, malformed/oversized bodies, redirects, timeout/network errors, unreadable ciphertext, reflected secret/raw-error redaction, rotation/disable/deletion during a request, discarded late results, missing selected models, current failures invalidating past catalogs, per-config locks, route throttling, and bounded 20-check history.
- `ProviderAdapterTest`: exact documented-ID/account-list intersection (not prefix guessing), deprecation/shutdown handling, distinct Realtime/TTS voice sets, compatible voice selection, Gemini models/voices with their different pagination schemas, Live versus dedicated TTS/custom/expired voices, pagination loops/limits, Deepgram authenticated project matching and STT/TTS metadata, optional balance failure, ElevenLabs account authentication versus a public catalog, tier/professional/high-quality voice compatibility, allowance normalization, trusted HTTPS/path/query restrictions, TLS/timeout/no-redirect settings, URL-secret reflection prevention, and nonserializable/redacted credential objects.
- `PlatformProviderTest`: no self-granted operator role, explicit confirmed CLI grant/revoke with audits, separately scoped platform routes/policies, organization isolation even for an operator, shared inheritance/local override, no implicit cross-scope fallback, native realtime never supplying a modular pipeline, and service-level creation authorization.
- `ProviderSecurityReviewTest`: debug-enabled unexpected HTML/JSON failures on both scope families with actual sanitized log-file inspection; all service-level mutation/test authorization; membership-before-query routing; stale operator/revoked in-flight actors; reflected language/numeric usage secrets and future-adapter guards; safe failure codes/exception arguments; malformed/expired defaults and fallbacks; distinct authorized modular resolution; strict Gemini expiry; malformed ElevenLabs restrictions; immediate cached-map/voice withdrawal; and Laravel/Guzzle-wrapped streaming-size guards.

Database feature tests use isolated in-memory SQLite with `RefreshDatabase`. Fixtures create data **only in tests**; the normal seeder is intentionally empty.

### What the HTTP fixtures prove — and do not prove

`Http::fake()` and `preventStrayRequests()` deliberately exercise documented successful/error protocol shapes without real accounts, charges, or network dependence. They establish adapter logic, safe persistence/redaction, authorization, compatibility, and failure behavior. They do **not** establish that a vendor accepted a real key, enabled a selected model, granted inference access, generated speech, or supported the future worker.

No testing-only success route, environment bypass, fake catalog, demo connection status, or seeded shared credential is present in the running product. Main preview data was not populated with fixture providers.

## Frontend/browser commands and isolation

```sh
npm ci
npx playwright install chromium
npm run test:browser
npm run format:check
npm audit
```

`test:browser` first builds assets, then Playwright starts a separate Laravel server on port 8001 with `APP_ENV=e2e`, ignored `database/browser-tests.sqlite`, isolated cookies, and a synthetic microphone. `e2e` intentionally keeps real HTTP CSRF enforcement, unlike Laravel's unit-testing environment.

Do not point the suite at a customer application. `PLAYWRIGHT_BASE_URL` is only for an intentionally isolated externally managed test server. Alternative binaries/JSON launch flags can be provided using `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` / `PLAYWRIGHT_CHROMIUM_ARGS`. Normal installations use managed Chromium.

The complete **16-check** desktop/mobile run covers:

- Homepage rendering/responsiveness, navigation, keyboard use-case tabs, FAQ, reduced motion/persistence, non-WebGL fallback.
- Registration through saved draft edits/search/deletion/settings to logout; setup/playground/call views and accessible empty states.
- Actual HTTP CSRF rejection, including the provider Test Connection route.
- Microphone lifecycle, dialog/page cleanup, late permission-grant cancellation.
- Provider library, all four setup forms and allowed categories, instructions/comparison, validation old-input redaction, encrypted save represented as **Not tested**, empty replacement fields, genuine failed check/history, no usable model selector after failure, rotation invalidating evidence, key removal disabling tests, and deletion.
- No secrets in successful provider response bodies, application localStorage/sessionStorage/cookies/URLs; no IndexedDB databases, CacheStorage entries, or service-worker persistence in those flows.
- Axe checks/overflow checks on the covered provider pages as well as foundation flows; no uncaught JS errors in those flows.

The provider browser test does **not** intercept backend responses. It intentionally submits an invalid nonsecret sentinel through the real transport and accepts a genuine failure (provider rejection or blocked outbound networking), never simulated success. No actual key or inference request is used. The success-only catalog selector is covered by backend rendering/compatibility tests; successful account-specific browser selection/live entitlement still needs a reachable real account.

Axe/mobile emulation are regression tools, not a formal accessibility certification or physical Safari/iOS test. Synthetic microphone audio does not verify quality, real generated speech, interruptions, language pronunciation, or latency.

## Genuine external diagnostics — not successful live acceptance

On November 10, 2026, direct calls through each implemented adapter using an intentionally invalid diagnostic sentinel attempted the real fixed API endpoints:

| Provider   | Actual outcome  | HTTP status   |
| ---------- | --------------- | ------------- |
| OpenAI     | `network_error` | None received |
| Gemini     | `network_error` | None received |
| Deepgram   | `network_error` | None received |
| ElevenLabs | `network_error` | None received |

The sandbox's outbound host restrictions prevent credential verification. These checks prove honest network-failure handling only; they do **not** prove invalid credentials, correct live authentication, vendor availability, or inference access. No configuration was written to the primary database by these diagnostics. Real keys should be entered only in the encrypted UI on a deployment with the required outbound HTTPS; never in chat or fixture files.

## Installation/build and dependencies

Verified locally: isolated SQLite fresh migration → rollback last provider migration → reapply, key-preserving repeated installer from M1, route registration, Blade compilation, production asset build without oversized-chunk warnings, PHP/source formatting, and Composer manifest validity. Build/runtime/tooling/databases/test traces remain ignored.

The npm audit reports zero current advisories for application dependencies. Composer's advisory audit was **not** completed against restricted Packagist; run `composer audit --locked` in a release environment with access. The included CI workflow requests it. The scoped M2 review is documented in [SECURITY_REVIEW.md](SECURITY_REVIEW.md); none of these checks is an independent penetration test or production-security certification.

## Acceptance gates still open

- Authenticated real-key provider/model/voice visibility, restricted account scopes, actual allowance snapshots, inference entitlement/quota, and successful account-specific browser selection on a reachable deployment.
- MySQL runtime/migration/concurrent-assignment verification, other PHP versions/browsers/real devices, and production cache/proxy/TLS settings.
- Real worker/session authorization, generated audio, language/format/streaming/interruptions, independent contexts, cleanup/recovery, and measured voice latency.
- SIP/incoming/outgoing calling, signed/idempotent callbacks, real transfer confirmation, transcripts/recordings/knowledge/attributed usage/cost/billing.
- Production load/security/accessibility review, email recovery/verification, MFA, general membership administration, retention/legal policies, backup/restoration and encryption-key-rotation drills.

No gate is closed by a credential being saved or a mocked HTTP success. Keep local implementation verification and genuine live-service acceptance separately recorded.
