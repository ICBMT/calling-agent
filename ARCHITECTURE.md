# Voca — architecture and phased implementation

## Original repository audit (2026-11-10)

The initial repository contained only `callingapplicationinstruction.txt`. There was no existing application, authentication, database, frontend, or test suite. Node 22 was available; PHP, Composer, MySQL, Redis, and Docker were not preinstalled. A sandbox-only portable PHP 8.4.27 / Composer 2.10.3 runtime was provisioned in ignored tooling. The application now uses Laravel 13.40.0 with PHP ^8.3-compatible locked dependencies; PHP 8.4 is the runtime verified locally.

The master brief is preserved. Work is phased; no seeded calls, transcripts, usage, or fake connection successes are allowed.

## Architecture boundaries

1. **Laravel control plane** — Blade views, session authentication, CSRF, form requests, policies, organization-scoped queries, auditable persistence, and encrypted provider configuration.
2. **Presentation** — Tailwind/purpose-built CSS, Alpine for small controls, GSAP/ScrollTrigger and Lenis storytelling, progressively enhanced Three.js sculpture. Self-hosted assets, relative browser service URLs, reduced motion, and non-WebGL fallback remain mandatory.
3. **Provider control plane (M2 implemented)** — capability-specific configuration, encrypted keys, four discovery adapters, fixed-origin bounded HTTPS, real read-only connection checks, normalized documented/account capabilities, explicit compatible default/fallback resolution. Authenticated catalog access is **not** inference permission or an audio test.
4. **Voice data plane (M3 delivered)** — separate LiveKit Agents worker for native realtime speech-to-speech over WebRTC; Laravel control plane manages session authorization, authority leases, and room dispatch; browser transport dynamically loads LiveKit SDK, manages on-demand microphone, and muting/barge-in interruption via `voca.interrupt` RPC. Continuous audio does not pass through ordinary PHP controllers or Laravel queue jobs.
5. **Telephony (later)** — signed, idempotent callbacks and provider/SIP media into the voice plane. Outbound consent, suppression, schedules, limits, and emergency stop precede campaigns.

## Current directory layout

```text
app/
  Console/Commands/{InstallVoca,PlatformAdmin,VoiceSessionReaperCommand}.php
  Http/Controllers/{Auth,Workspace}/
  Http/Requests/{Providers,StartVoiceSessionRequest}.php
  Http/Middleware/{EnsureWorkspace,ProviderScope,SecurityHeaders}.php
  Models/{User,Organization,Agent,CallRecord,AuditLog,ProviderConfiguration,ProviderConnectionTest,VoiceSession}.php
  Policies/
  Services/
    Providers/
      Adapters/{OpenAi,Gemini,Deepgram,ElevenLabs}Adapter.php
      Contracts/ProviderAdapter.php
      Data/{ProviderCredentials,Discovery,ResolvedProvider}.php
      {Capability,Catalog,ProviderRegistry,ProviderHttpClient,ProviderExceptionBoundary}.php
      {ProviderConfigurationStore,ProviderConnectionTester,ProviderManager}.php
    Voice/
      {VoiceInfrastructure,VoiceGateway,VoiceTokens,VoiceSelection,VoiceSessions,VoiceWorkerAuth,VoiceFailure}.php
bootstrap/
config/{providers,voice}.php
database/{factories,migrations,seeders}/
resources/
  css/
  js/
    marketing/
    workspace/
      voice/{livekit-transport,performance,playground,protocol}.js
      microphone.js
  views/{auth,components,layouts,marketing,workspace}/
routes/
tests/{Feature,Unit,Browser,Js}/
voice-worker/
  agent.py
  pyproject.toml
  uv.lock
  voca/{captions,contracts,control,events,metrics,native,privacy,runtime}.py
  tests/
```

No LiveKit deployment/worker or pretend inference endpoint is included. Laravel's HTTP client is sufficient for the implemented read-only REST adapters; adding a provider SDK alone would not create voice functionality.

## Schema and tenancy

- Identity: users, organizations, unique role-bearing memberships, membership-validated current organization. Optional platform-provider operator flag defaults false and is not self-grantable.
- Foundation: draft-only agents, read-only call records, business preferences, transactional basic audit records.
- Providers: `provider_configurations` with one immutable provider/category, encrypted hidden key, auth identifiers, version/revision, normalized catalog/usage, selected model/voice, and explicit unique scoped routing assignment.
- Evidence: bounded `provider_connection_tests` with credential version, safe result/status, real HTTP status when received, timestamp/duration, and an applied/discarded flag.
- Concurrency: `provider_scopes` lock rows serialize configuration/routing/result writes. HTTP runs outside transactions. A separate cache lock prevents overlapping tests; current actor permissions are checked before applying results; revision + credential version + run token stop stale requests from revalidating edited/deleted keys.
- Platform audits use NULL organization IDs; platform-provider records require a separate operator boundary and are never returned through organization lists/ID lookups.

See [DATABASE_DESIGN.md](DATABASE_DESIGN.md) for fields, relationships, retention, and role boundaries. MySQL remains the production target, **not yet a verified runtime**.

## Provider-independent integration design

### Discovery is not the audio engine

`ProviderAdapter::discover(ProviderCredentials, Capability): Discovery` performs authenticated read-only API work. The registry enumerates actual supported categories. Exact dated mappings are intersected with account model lists only where a provider does not supply output-modality metadata; unknown model names are not classified by prefix. Deepgram and ElevenLabs capability/tier/voice metadata is normalized directly. Documented shutdowns and voice expiries prevent stale model/voice use.

The authenticated HTTP transport has a fixed trusted origin/path/query allowlist per provider, TLS verification, no redirects/cookies/retries, bounded time/response size/pagination, header authentication, and safe normalized error categories. Raw upstream bodies/messages/headers and reflected keys never reach response JSON, logs, audits, sessions, or persistent catalogs. Replacement inputs never receive a stored value. All normalized metadata is checked for reflected keys, including language/numeric usage fields. Provider-route unexpected exceptions have a generic no-store response and class-only reporting even when debug is enabled; exception argument capture is disabled at bootstrap. External request recorders still require deployment-level redaction. No arbitrary URL can become a credential-exfiltration destination.

### Resolving configurations

`ProviderManager` requires an explicit authorized `User` actor and checks current organization membership before any provider lookup/decryption. It returns server-only `ResolvedProvider` objects through separate `resolveNativeRealtime` and `resolveModularPipeline` methods. Resolution requires enabled configuration, a current-version authenticated catalog younger than 24 hours, a compatible exact model/voice, no documented shutdown/expiry, and an explicit routing assignment. Current official mappings also gate cached selections; removing a model/voice from the map immediately blocks it.

A local default overrides platform routing. If that local default becomes unusable, only the designated same-category **local fallback** is eligible; no credential-scope or category switch is silent. Without a local default, the explicit platform default/fallback pair may be inherited. Scope-lock transactions and a unique DB routing key ensure one default/fallback per category/scope. No live mid-call failover is claimed.

To add/switch providers, extend the registry, implement discovery, review documented capabilities/trusted transport routes, and add tests. The future voice engine consumes the capability boundary, not provider-specific Laravel controllers. Future media-specific realtime/STT/LLM/TTS/telephony interfaces will implement real streaming/session methods alongside this control-plane boundary rather than pretend those methods already work.

### Honest evidence levels

```text
Saved encrypted key
    ≠ successful authentication
Authenticated read-only catalog
    ≠ verified inference entitlement / funded quota
Compatible catalog selection
    ≠ verified language / audio formats / streaming
Real worker + microphone + generated audio
    ≠ verified SIP / answered telephone call
```

Status messages preserve these distinctions. Usage is an actual timestamped allowance/balance snapshot where exposed, or explicitly unavailable. No numeric pricing/latency guarantee or fabricated usage is displayed.

## Phases and acceptance gates

1. **M1 — foundation, completed and user-approved:** premium original homepage, working registration/authentication/workspace, persistent draft agents/settings, tenant isolation, honest empty call views, local microphone check, documentation and automated tests.
2. **M2 — secure providers, code implemented and locally verified:** encrypted organization/platform credential UI, four real read-only adapters, safe errors/history, capability-based model/voice selection, comparison/setup/usage, explicit scoped defaults/fallbacks, regression tests. **Authenticated live acceptance is still open**: no real keys supplied, sandbox provider egress blocked. Mocked tests cannot close that gate.
3. **Next — validated agent deployment and real browser voice:** versioned/published configuration, actual language/format/streaming/inference checks, LiveKit session grants, independent worker, genuine audio, interruptions/context isolation, measured timings and cleanup.
4. **Then — telephony:** signed/idempotent inbound callbacks/media and a real answered call, followed by lawful manual outbound, knowledge/handoff, campaigns, attributed usage/costs, and production hardening.

Do not start all later modules in an uncontrolled batch or turn draft agents active because a key was saved.

## Dependencies and development

Laravel 13, PHP 8.3+ (8.4 locally verified), Composer 2, Node 22.12+ (22.22.3 pinned for development), npm, SQLite locally/MySQL 8 production target, Blade/Tailwind 4/Alpine 3/Vite 8, GSAP/Lenis/Three.js. Database-backed sessions/cache/queues keep foundation/provider development usable without Redis or a worker. Redis/Reverb and operational queue/scheduler heartbeats are later modules, not claimed connected now.

Commands and verified results are maintained in [TESTING.md](TESTING.md); installation/key preservation and optional operator provisioning are in [INSTALLATION.md](INSTALLATION.md) and [PROVIDER_SETUP.md](PROVIDER_SETUP.md).

## Technical risks and release blockers

- This environment cannot reach provider API hosts. Real-key authenticated acceptance, paid inference, voice, and phone calls remain unverified; metadata mocks and negative network tests are not substitutes.
- Provider model IDs, modality APIs, voices, deprecations, scopes, regions, and pricing change. Dated maps are intentionally conservative and require official review before expansion. Catalog presence is not entitlement.
- `APP_KEY` loss/rotation can make encrypted keys unreadable. Backups require controlled key retention, access control, and tested restoration/rotation procedures. Local deletion does not revoke an upstream key or erase backups.
- MySQL, other PHP versions, real mobile hardware, Firefox/Safari, concurrency/load, formal security/accessibility review, MFA, account recovery/email verification, general membership/platform administration, privacy/legal retention procedures, and backup drills remain open gates.
- Native realtime cannot be flattened into an artificial modular pipeline. Audio cancellation, resampling, language/format compatibility, provider session limits, telephony, and full-duplex transport belong in the worker, not synchronous PHP HTTP.
- Composer advisory auditing needs unrestricted Packagist access; local manifest validation is not an advisory-audit pass. CI is defined, not claimed remotely run.
