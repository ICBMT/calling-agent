# Secure provider setup

**Milestone 2: implemented control plane. Documentation reviewed November 10, 2026.**

Provider configuration is available at **Workspace → AI providers** (`/app/providers`). Saving is local encrypted persistence. **Test Connection performs real server-side read-only requests**; it does not simulate success or generate text/audio. There is no working AI conversation, LiveKit worker, or telephone integration yet.

**Live acceptance is still pending:** no real provider keys were supplied. Real outbound diagnostics from this sandbox returned `network_error` for all four provider API hosts, without an HTTP response. Do not interpret mocked automated tests, saved credentials, or an authenticated catalog as a completed live voice test. Use a deployment with permitted outbound HTTPS for actual credential acceptance.

## Supported configuration categories

One connection has one immutable provider and one category. Add separate connections to use the same provider in different categories or to switch providers.

| Provider      | Native realtime     | Speech to text                  | Language model         | Text to speech                                         |
| ------------- | ------------------- | ------------------------------- | ---------------------- | ------------------------------------------------------ |
| OpenAI        | Realtime API models | Documented transcription models | Documented text models | Dedicated Speech API models, with deprecation warnings |
| Google Gemini | Gemini Live         | Not exposed by this adapter     | Documented text models | Dedicated Gemini TTS; kept distinct from Live          |
| Deepgram      | Not exposed         | Project STT catalog             | Not exposed            | Project TTS catalog; Aura model identifies its voice   |
| ElevenLabs    | Not exposed         | Not exposed                     | Not exposed            | Account TTS models and compatible account voices       |

“Not exposed” is a Voca implementation boundary, not a statement that the vendor has no such product. Google Cloud Speech, Vertex AI, ElevenLabs Agents, GPT-Live, Anthropic, Ollama, Whisper/Piper hosting, arbitrary OpenAI-compatible endpoints, and telephony are **not supported adapters** in this milestone. They are not configurable merely by entering a URL.

## Everyday setup — no PHP or environment editing

1. Sign in as an **owner or admin** of the correct workspace.
2. Open **AI providers**, choose a provider, and select a supported category.
3. Enter a friendly connection name, the API key, and any applicable project/organization identifier. Never put a secret in the name, an agent prompt, chat, or developer tools.
4. Save. The UI must say **Not tested**, not “connected.” No external request is made on save.
5. Run **Test Connection**. It authenticates, reads the compatible catalog, and records a safe result, UTC timestamp, actual HTTP status when received, and elapsed request time.
6. After a successful check, select a returned **model** and, for speech, a compatible **voice**. Inference permissions, billing, synthesis, audio transport, and language quality remain untested.
7. Optionally designate this connection as the **default** or **explicit fallback** for its category and scope. This replaces the previous assignment for that role. It does not deploy or activate an agent.

Workspace members can read redacted details/status, not change keys, run checks, or assign routes. Resource IDs, hidden inputs, query parameters, and browser-supplied organization IDs cannot bypass the organization boundary.

### OpenAI

- Create a project API key in [OpenAI API keys](https://platform.openai.com/api-keys). ChatGPT subscriptions do not include API billing.
- Allow **model-list read access**. Optional project and organization identifiers become `OpenAI-Project` / `OpenAI-Organization` headers. Standard keys are used; an organization-admin billing key is not requested.
- Authentication/discovery: `GET https://api.openai.com/v1/models`, `Authorization: Bearer …`.
- The models endpoint does **not** report output modalities or voice entitlement. Voca intersects returned IDs with the exact, dated official capability map in `config/providers.php`. Unknown models, image/embedding models, unrelated endpoints, and shut-down IDs do not become selectable by prefix matching. `gpt-4.1-nano` is excluded because its official model page marks it shut down. Current mappings also revalidate cached selections, so removing/reclassifying a model or built-in voice blocks it immediately.
- Realtime has its own documented built-in voice set. Dedicated Speech API voices are model-specific; Voca validates their intersection on the server.
- The current official documentation marks `tts-1`, `tts-1-hd`, and `gpt-4o-mini-tts` deprecated, with shutdown **2027-01-06**. Legacy Whisper / gpt-4o transcription IDs are marked deprecated with shutdown **2027-02-26**. The UI shows deadlines and stops accepting those IDs on their documented shutdown date, even if an old snapshot remains. Realtime models are **not** silently routed through `/audio/speech`.
- Account usage is **unavailable in this standard-key integration**. Use the [actual usage console](https://platform.openai.com/usage); Voca displays no fabricated totals or cost.

Official evidence: [models list](https://developers.openai.com/api/reference/resources/models/methods/list), [model catalog](https://developers.openai.com/api/docs/models), [Realtime conversations and voices](https://developers.openai.com/api/docs/guides/realtime-conversations), [Speech API models and voices](https://developers.openai.com/api/docs/guides/text-to-speech), [transcription models](https://developers.openai.com/api/docs/guides/speech-to-text).

### Google Gemini

- Create a key for an eligible project in [Google AI Studio](https://aistudio.google.com/api-keys). Enable the Generative Language API and any required billing/project verification.
- Use an authorization key or an appropriately restricted standard key. Browser-referrer-only restrictions do not permit these server-side requests. This is the Gemini API, not Vertex AI service-account authentication.
- Model discovery: `GET https://generativelanguage.googleapis.com/v1beta/models` using **`x-goog-api-key`**, never `?key=…`.
- For speech categories, also read `GET /v1beta/voices` using the same header. Models use `pageSize`/`pageToken`; the current Voices API uses `page_size`/`page_token`. Both are bounded and paginated.
- Returned exact model IDs are intersected with the reviewed modality map. `generateContent` appearing in metadata alone does not make an embedding/image/TTS model a general language model.
- Gemini Live is a native realtime category. Only returned **prebuilt** voices are exposed for Live here. New dedicated Interactions TTS models may use returned prebuilt, prompted, or replicated voices; legacy TTS choices are restricted to prebuilt voices. Expired or malformed custom-voice timestamps are excluded, and their expiry is rechecked on selection/resolution.
- Voice creation/cloning/consent uploads are not implemented. Account usage is not fetched with this API-key adapter; see [AI Studio usage](https://aistudio.google.com/usage).

Official evidence: [key/security setup](https://aistudio.google.com/docs/api-key), [models](https://aistudio.google.com/docs/models), [list models](https://aistudio.google.com/api-reference/models/list-models), [Live capabilities](https://aistudio.google.com/docs/live-api/capabilities), [TTS and model differences](https://aistudio.google.com/docs/speech-generation), [list voices](https://aistudio.google.com/api-reference/voices/list-voices).

### Deepgram

- Create a project key and copy the **project UUID** from the [Deepgram console](https://console.deepgram.com/).
- Permit project read and project model discovery. Balance read is optional. Missing inference permissions are not checked by a read-only metadata test.
- Verify the authenticated project: `GET https://api.deepgram.com/v1/projects/{project_id}`, `Authorization: Token …`. The returned project ID must match.
- Then discover `GET /v1/projects/{project_id}/models?include_outdated=false`. The **public** model catalog alone never proves authentication or project access.
- STT and TTS come from the respective `stt[]` / `tts[]` metadata, using actual canonical model IDs and language lists. STT streaming is displayed only when reported. TTS catalog metadata does not by itself verify transport/streaming behavior.
- Aura's canonical model identifies the voice: choosing an unrelated voice/model pair is rejected.
- Optional `GET /v1/projects/{project_id}/balances` supplies real balances and currency units. Denial, timeout, or malformed optional balance metadata produces a separate safe warning and **no invented balance**. It does not negate a successful authenticated project/catalog check. A balance is not Voca-attributed usage or an invoice.

Official evidence: [project](https://developers.deepgram.com/reference/manage/projects/get), [project model list](https://developers.deepgram.com/reference/manage/projects/models/list), [public model metadata](https://developers.deepgram.com/reference/manage/models/list), [official OpenAPI schema](https://github.com/deepgram/deepgram-api-specs/blob/main/openapi.yml).

### ElevenLabs

- Create an account/workspace key in the [API-key console](https://elevenlabs.io/app/settings/api-keys) with **User read, Models read, and Voices read** permissions. Actual synthesis permissions are a later voice-engine requirement.
- Authenticate first with `GET https://api.elevenlabs.io/v1/user/subscription`, `xi-api-key: …`. A subscription response must have the documented tier and integer allowance counters. A public `/v1/models` success alone never proves a key works.
- Read `/v1/models`; expose only `can_do_text_to_speech=true`, excluding alpha-access models whose entitlement is not established.
- Discover account voices using `/v2/voices`; pagination follows `has_more` and `next_page_token`, not unstable `total_count`.
- Exclude incompatible account tiers and restricted voices. Professional voices require the selected model's `serves_pro_voices` and `fine_tuning.state[model_id]=fine_tuned`. High-quality voices require the reported compatible base model ID. No synthesis entitlement, quality, or voice-rights claim is inferred from catalog membership.
- Only actual character-count/allowance values and the tier are retained from the subscription response. Invoices, payment information, full account responses, preview URLs, audio samples, and voice-cloning input are not stored.

Official evidence: [subscription/authentication metadata](https://elevenlabs.io/docs/api-reference/user/subscription/get), [model capabilities](https://elevenlabs.io/docs/api-reference/models/list), [paginated voices and compatibility metadata](https://elevenlabs.io/docs/api-reference/voices/search).

## Status meanings and failures

| UI state                | Meaning                                                                                                                          |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Not tested              | A key is saved; no current successful request is assumed.                                                                        |
| Authentication verified | Authenticated read-only catalog checks succeeded for this credential version within 24 hours. **Not voice/inference readiness.** |
| Recheck required        | The successful snapshot is older than 24 hours. Selection and backend resolution are blocked.                                    |
| Check failed            | The latest current-version check failed. Old catalogs/usage cannot authorize resolution.                                         |
| No credential           | The stored key was removed. Testing is disabled until a key is saved again.                                                      |

The normalized failure includes its **real HTTP status when received**, plus a safe, actionable category: invalid credentials, permissions/restrictions, quota, rate limiting, missing resource, provider outage, unexpected schema, excessive response/catalog, blocked redirect, unreadable encryption, or network/time limit. Raw upstream messages can reflect keys and are **never** saved, flashed, rendered, or logged. A network error does not prove that a credential is invalid.

A successful check with zero supported models still means **no selectable model and no eligible routing**. If a previously selected model/voice disappears, Voca reports `selection_unavailable`, blocks resolution, and never substitutes a different choice silently.

A late test result is discarded if its initiating actor loses membership/management/operator rights or is deleted, as well as when the configuration/key revision changes.

Checks are limited to 5/minute/user and 15/minute/scope, and locked per configuration. Each request has a 3-second connect / 8-second total cap; multi-request discovery has a 25-second overall budget. Responses are capped at 2 MiB, catalogs at 1,000 entries per list and five pages. Redirects, arbitrary origins/paths/query parameters, credential reflection into URL tokens, and unrecognized success/schema responses are rejected. There are no automatic HTTP retries or inference calls.

## Credential lifecycle and encryption

- Laravel authenticated encryption (`encrypted` cast) protects the database `api_key` field. The encryption key remains server-side in the existing `APP_KEY`; never regenerate it casually.
- Stored keys are hidden from model JSON, views, debug summaries, audit records, sessions/validation old input, browser scripts, and application browser storage. Newly entered keys necessarily exist transiently in the form and HTTPS request; browser extensions/password managers are outside application control. Replacement fields are always empty. There is no reveal endpoint or suffix preview.
- Leaving replacement blank preserves the existing key. Replacing a key (even with the same value), changing project/organization IDs, or removing a key invalidates current evidence, catalog, usage, model/voice selection, and routing. Removing the key also disables the configuration.
- Versioned history explains which key version a check used. An edit, disable, replacement, or deletion during an in-flight request prevents that request from revalidating the edited/deleted configuration. Stale forms cannot overwrite newer edits.
- **Remove stored key** and **Delete connection** affect Voca only. Revoke the original key in the provider console. Encrypted backup copies remain subject to the installation's backup retention.
- Unexpected provider-route failures return generic no-store errors even with debug enabled; the application logs only the exception class, never the original message/trace. Normalized metadata is checked for reflected keys before persistence. Bootstrap disables exception argument capture, and sensitive DTO/service parameters have PHP masking.
- Do not enable HTTP request-body/header logging or developer exception pages on a public deployment. Redact `Authorization`, `x-goog-api-key`, `xi-api-key`, `api_key`, and credentials in any future monitoring integration; set `zend.exception_ignore_args=On` in production. No external request recorder/monitoring package is installed here.

## Optional shared platform credentials

Ordinary registration creates a workspace owner, **not** a platform operator. Organization ownership and a browser-submitted role can never grant platform access.

An installation operator may explicitly provision an existing account from a trusted server shell:

```sh
php artisan voca:platform-admin owner@example.com
# Interactive confirmation is required. --force is only for trusted automation.
# To revoke:
php artisan voca:platform-admin owner@example.com --revoke
```

The account can then open `/app/platform/providers` or the platform-management link in AI providers. This is a distinct scope and authorization boundary. A platform operator does **not** gain arbitrary access to organization credentials. Persisted operator permission is rechecked for operations, including stale server-side actor objects. Grants/revocations are audited, and no operator account or shared key is seeded.

Only enabled, freshly checked, compatible platform routes are eligible for server-side inheritance. A local default overrides the platform pair. If a local default later fails, only an explicit **local same-category fallback** may replace it; the resolver never silently changes credential scope. Without a local default, only the platform default/fallback pair is considered; a lone local fallback is not an override. No live mid-call failover is implemented yet.

## Extending providers without rewriting the voice engine

`ProviderAdapter` exposes genuine read-only discovery; `Capability` keeps native realtime distinct from STT/LLM/TTS. `ProviderRegistry` selects adapters; `ProviderManager` resolves tenant-aware verified defaults/fallbacks and returns a server-only `ResolvedProvider`. All three public resolution methods require an explicit `User` actor and check current organization membership before querying or decrypting credentials, including shared inheritance. It has separate native-realtime and modular-pipeline resolution methods, **not pretend audio-generation methods**.

To add a supported provider: verify official auth/endpoints/modalities, implement a discovery adapter, add its registry definition and trusted transport origin/path/query allowlist, normalize only safe metadata, and add success/failure/security tests. Update dated exact-ID maps when the provider does not expose capabilities. No future audio worker should depend directly on one vendor's controller or JSON format.

The later worker must additionally validate language, input/output formats, streaming, interruption, inference permissions, telephony compatibility, and actual audio. M2 catalog eligibility is deliberately not a substitute for that activation gate.

## Live acceptance checklist — still open

Use real keys **only through the encrypted UI on a reachable deployment**, never in chat, test fixtures, source, command-line history, or screenshots.

- Confirm a successful read-only authentication/catalog check with each intended account and restricted key.
- Confirm selected model and voice visibility, permissions, real allowance snapshots where supported, and safe 401/403/429 handling.
- Rotate/remove the credential and verify previous evidence can no longer authorize resolution.
- Record actual dates/provider configuration IDs and sanitized outcomes, never secrets.
- Verify MySQL behavior and production proxy/TLS/cache settings separately.
- Before operational voice: implement and genuinely test the independent LiveKit/audio pipeline. No such gate is marked passed here.
