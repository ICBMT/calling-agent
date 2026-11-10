# Database design

## Current schema — foundation + secure providers

SQLite is the verified local/test database. The seven application/framework migration files use Laravel's database abstraction and target MySQL 8 in production, but MySQL has **not** been exercised in this sandbox. Portable migration syntax is not MySQL acceptance.

### Identity and tenancy

- **users** — unique normalized email, hashed password, remember token, nullable `current_organization_id`, and `is_platform_admin` defaulting to **false**. The operator flag is not mass assignable or accepted by registration/settings. Only an explicit server-side provisioning command grants/revokes it. Provider operations check the persisted permission, including when a caller holds an older User object.
- **organizations** — name, unique ULID-suffixed slug, IANA timezone, default draft-agent language, timestamps.
- **organization_user** — membership, owner/admin/member role, timestamps, unique `(organization_id, user_id)` pair, cascading foreign keys.

An active organization reference alone grants no access. `EnsureWorkspace` resolves it **through the authenticated user's membership** on every workspace request. A forged/stale reference fails with 403.

| Action                                                               | Owner | Admin | Member | Platform operator                       |
| -------------------------------------------------------------------- | ----- | ----- | ------ | --------------------------------------- |
| Read resources of a workspace where a member                         | Yes   | Yes   | Yes    | Only via that workspace membership      |
| Mutate draft agents / business settings                              | Yes   | Yes   | No     | Only via the appropriate workspace role |
| Mutate/test workspace provider credentials and model/routing choices | Yes   | Yes   | No     | Only via the appropriate workspace role |
| Read/mutate/test shared platform providers                           | No    | No    | No     | Yes, separately scoped routes/policy    |
| Inspect another organization's credentials without membership        | No    | No    | No     | No                                      |

Membership invitation, role-editing UI, organization switching, granular permission tables, and a general platform-administration console are not implemented. M2 adds only the explicitly provisioned **platform-provider operator** boundary.

### Agents

**agents** stores organization ownership, identity, purpose, optional description, personality, requested language, instructions, optional greeting, draft-only status, timestamps, and soft deletion.

- `(organization_id, status)` is indexed; only `draft` is accepted at the current database layer.
- Client-submitted tenant IDs and activation/status changes are prohibited.
- Instructions are limited to 20–12,000 characters.
- English/Urdu/Hindi are saved preferences, not verified speech/language support.
- Provider selections in M2 are scope/category routing preferences, **not published agent deployments**. Agent versions and per-agent provider assignments come with the next validated builder/voice milestone.

### Secure providers

**provider_configurations**:

- Nullable `organization_id` with cascading FK, and explicit `scope` (`organization` or `platform`). Organization scope queries require the resolved organization's ID; platform queries require `organization_id IS NULL` and separate operator authorization.
- Immutable provider identifier and one capability (`realtime`, `stt`, `llm`, `tts`), display name, enabled flag, and applicable nonsecret project/organization identifiers.
- Nullable **encrypted text `api_key`**. Laravel authenticated encryption uses the existing `APP_KEY`. This attribute and the in-flight test token are hidden from ordinary model serialization/debug summaries; no reveal endpoint exists. Project/account IDs are metadata, not API secrets.
- Exact selected model/voice IDs validated against a **server-saved normalized catalog** and current documented mappings where applicable, not browser-supplied capability claims. Removed/reclassified mapped models or built-in voices cannot be grandfathered by cached catalogs.
- `routing_role` (`none`, `default`, `fallback`) and nullable unique `routing_key` encoding scope + organization + capability + role. This DB uniqueness guard permits only one assignment for each role/category/scope while allowing multiple unassigned connections. Scope-lock transactions serialize competing assignments and invalidate replaced assignments' form revisions.
- `credential_version` increments when key or authentication identifiers change. Such changes clear evidence, catalog, usage, selections, and routing. Removing the key also disables the connection.
- Optimistic `revision` detects stale forms; unique `test_token` and version/revision comparisons reject late results after concurrent edits/removal/deletion.
- Current read-only `connection_status`, tested version, last test/success timestamps, safe message/error category, and actual HTTP status when received. A success is never named end-to-end voice readiness.
- `catalog` JSON: normalized model/voice identifiers, safe labels, documented/API capability evidence, reported languages/streaming, compatibility IDs, and relevant shutdown/voice-expiry metadata. No complete raw response, voice sample, preview URL, prompt, or secret is retained.
- `usage_snapshot` JSON: only actual fetched account allowance/balance values with a timestamp, or NULL when unavailable. It is not a Voca consumption/cost ledger. Warnings are static/redacted explanations of limitations/optional failures.
- `(scope, organization_id, capability)` index for explicit scoped resolution. Successful catalogs expire after 24 hours; documented shutdowns and voice expiry are also checked at selection/resolution time.

**provider_connection_tests**:

- Configuration FK with cascading deletion, nullable actor FK, credential version, outcome/code, fixed safe message, HTTP status when available, measured duration, supported-model count, applied/discarded flag, and UTC timestamp.
- Only the most recent **20 checks per configuration** are retained after each completed check.
- No request body/header, raw error, full provider payload, or credential is stored. A successful old-version/discarded check cannot authorize the current configuration.
- If a configuration was deleted while HTTP was in flight, no history or configuration is recreated. The persisted initiating actor is reauthorized before applying results; revoked/downgraded/deleted actors produce discarded evidence (with NULL actor when deleted), not a current verification.

**provider_scopes**:

- Internal primary-key lock row (`organization:{id}` or `platform`), plus nullable cascading organization FK.
- Used inside short database transactions to serialize provider edits, routing assignments, and result application. **External HTTP runs outside those transactions.** Per-configuration cache locks additionally prevent overlapping test requests.

This is configuration/catalog infrastructure, not operational media. No table claims a voice worker, telephony adapter, generated call, real transcript, or provider inference measurement exists.

### Real-time browser voice sessions (Milestone 3)

**voice_sessions**:

- Primary UUID key (`id`).
- Foreign keys: cascading `organization_id`, nullable `user_id` (the actor), nullable `agent_id`, and nullable `provider_configuration_id`.
- `credential_version` and `configuration_revision`: tracked to invalidate in-flight sessions immediately if provider credentials or configurations rotate.
- `architecture`: defaults to `native_realtime`. Modular pipelines remain explicitly separate.
- `state`: strictly transitions through `provisioning` → `dispatched` → `starting` → `active` → `ended` / `failed` / `expired` / `revoked`.
- Unique `active_user_key`: encodes `organization_id + user_id` while active. Enforces at most one active session per user per tenant at the database constraint level. Cleared upon entering any terminal state.
- `room_name` (unique LiveKit room name), `participant_identity` (browser client), and `worker_identity` (LiveKit worker).
- `dispatch_id` and `worker_job_id`: LiveKit dispatch and job identifiers for tracking and revocation.
- Encrypted `configuration_snapshot`: transient copy of draft agent instructions and greeting; erased as soon as the session enters a terminal state.
- Single-use `claim_hash` and rotating `lease_hash`: verify worker claim authority and periodic heartbeats.
- Timestamps: `claim_expires_at`, `expires_at` (10-minute session cap), `claimed_at`, `started_at`, `heartbeat_at`, `ended_at`.
- `error_code`: safe enumerated failure reason (e.g. `session_expired`, `worker_timeout`, `provider_quota`, `access_revoked`).
- `cleanup_pending` and `room_deleted_at`: track room deletion status in LiveKit, ensuring rooms are drained and deleted even across server restarts or outages.
- `event_sequence`: monotonically increasing sequence number for event updates.
- `metrics` JSON: bounded numeric metrics allowlist (`provider_first_audio_ms`, `worker_turn_ms`, tokens, turns, interruptions). Never stores transcripts, raw prompts, or audio data.

**voice_worker_nonces**:

- `hash`: SHA-256 hex string primary key.
- `expires_at`: timestamp with index. Protects internal worker HMAC routes against replay attacks; expired nonces are purged periodically.

### Call-record foundation

**calls** stores unique public UUID, organization, nullable agent reference, direction, lifecycle status, caller/destination numbers, timestamps, and nullable duration.

- Indexed by organization + created time/status; all views/metrics are scoped to authenticated membership.
- Related agent details are organization-constrained even for incorrectly linked records.
- There is no call-creation endpoint, telephony ingestion, transcript, recording, invented cost, or seeded call activity.
- Future ingestion must verify the agent belongs to the same organization. FK existence is not an ownership check.

### Audit and framework operations

**audit_logs** records nullable organization, nullable actor, action, optional subject type/ID, and creation time. Organization-less records are reserved here for platform-provider changes and operator provisioning. Workspace, agent, settings, provider CRUD/selection/test, and operator grant/revoke mutations are recorded transactionally. No request bodies, API keys, passwords, instructions, or upstream errors are copied into audits. This is a basic operational trail, not a tamper-proof compliance ledger.

Framework session, cache/cache-lock, job/batch/failed-job, and password-reset-token tables remain. Sessions default to encrypted database persistence. Password-reset storage does not mean an email-reset workflow exists. Database cache supports cross-request throttles/locks without Redis; an array cache is only for isolated tests, not production rate limiting.

## Query and authorization boundaries

Resources are retrieved via organization relationships or explicit `forScope($organization)` / separately authorized `forScope(null)` queries **before exposure**. Foreign resource IDs return 404. Policies independently enforce mutations. Platform-provider operators cannot use their routes to reach organization configurations. Query/form scope, organization, provider-status, capability-catalog, endpoint, routing-key, and operator-role injection are rejected or never used as authority.

There is no magical global tenant scope. Future jobs, controllers, API endpoints, and workers must explicitly carry organization context. Every public provider-resolution method requires an explicit actor and current organization membership before any provider query/decryption. The internal provider resolver may then consume deliberately shared platform credentials server-side without exposing those records/secrets to organization users.

## Future schema — introduced with verified modules

| Module            | Planned entities                                                            | Acceptance gate                                               |
| ----------------- | --------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Agent publication | Versions, per-agent provider assignments, voices/languages/deployments      | Full capabilities and explicit activation validation          |
| Knowledge         | Bases, documents/chunks/embeddings, ingestion jobs, agent assignments       | Secure tenant-isolated ingestion/retrieval                    |
| Voice and calls   | Sessions/events/transcripts/recordings/summaries/transfers                  | Real audio and lifecycle events                               |
| Telephony         | Numbers, routes/assignments, webhook receipts                               | Signed/idempotent callbacks and a genuine call                |
| Campaigns         | Contacts/attempts/suppression/calling-hour limits                           | Consent, limits, emergency shutdown                           |
| Usage             | Attributed inference usage/costs, alerts, pricing versions, invoices        | Actual provider measurements; estimates distinct from billing |
| Operations        | Granular permissions, notifications, health history, configurable retention | Authorized administration/monitoring and restoration tests    |

## Retention and encryption backups

Agent deletion is soft. Provider key removal nulls the encrypted value and disables local routing; configuration deletion cascades its bounded test history but retains the basic mutation audit. Neither action revokes the upstream API key or erases historical encrypted backups. Revoke at the provider and apply the operator's backup-retention policy.

Organization hard deletion cascades organization-owned rows; no organization/account deletion UI is exposed yet. Platform records are not accidentally owned by the operator's current organization. Back up and restore `APP_KEY` securely with the database. Loss or casual rotation makes stored provider ciphertext unreadable; production rotation requires a separately tested Laravel previous-key/re-encryption procedure, not a blind `key:generate`.
