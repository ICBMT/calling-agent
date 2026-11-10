# Working on Voca

Read `callingapplicationinstruction.txt`, `ARCHITECTURE.md`, and `PROJECT_STATUS.md` before extending the application. Work in verified phases. Do not describe placeholder configuration or mocked test responses as live voice/telephony integrations.

## Stack

Laravel 13, PHP 8.3+, PHPUnit 12 (PHP 8.3+), Blade, Tailwind 4, Alpine 3, Vite 8, GSAP, Lenis, Three.js. SQLite is the default local database; MySQL 8 is the production target. Install PHP 8.4+ for development to match the verified sandbox runtime.

## Commands

- `composer install` / `npm ci`
- `php artisan migrate`
- `php artisan test`
- `vendor/bin/pint --test`
- `npm run build`
- `npm run test:browser` (requires Playwright Chromium)

## Invariants

- Resolve the active organization through authenticated membership, never a client-submitted organization ID.
- Scope resource retrieval to that organization and authorize mutations with policies.
- No seeded demo calls, usage, or transcripts. Draft agents cannot be activated before the voice/provider milestones.
- Never return a stored provider key to frontend code, JSON, logs, audit metadata, debug output, or validation old input. M2 uses encrypted hidden credentials; preserve APP_KEY.
- Keep provider origins/paths/query parameters fixed and trusted; block redirects. Unknown model IDs are not capabilities. Authentication/catalog checks never imply inference, audio, or calling readiness.
- Every `ProviderManager` resolution requires an explicit actor and current organization membership before any provider query/decryption. Never add a worker/HTTP bypass. Current exact mappings must also gate cached selections; absent/retired models and malformed restrictions fail closed.
- Preserve scope/version/revision checks and explicit compatible default/fallback routing. Platform-provider operators are separately provisioned; organization ownership never grants that role.
- Mocked adapter tests use isolated test databases; never seed connection successes or fixture catalogs into a user workspace. Genuine live acceptance needs real keys and reachable provider APIs.
- Build animation as progressive enhancement. Respect reduced motion and handle unavailable WebGL.
- Keep all assets self-hosted and browser API URLs relative/same-origin.
- Keep `.env`, `.tools`, databases, `vendor`, `node_modules`, and build output out of Git.
- Update status/docs and add regression tests with every module.
