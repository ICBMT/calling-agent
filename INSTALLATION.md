# Installation and operations

## Release boundary

This installs a working Laravel **foundation**, not a finished AI calling service. See PROJECT_STATUS.md before offering it to end users as a calling product.

## Requirements

- PHP **8.3+**; PHP **8.4.27** is the verified sandbox runtime.
- Composer 2.
- Node **22.12+** and npm; Node **22.22.3** is verified here.
- PHP extensions: ctype, curl, dom, fileinfo, filter, hash, iconv, mbstring, openssl, PDO, pdo_sqlite (or pdo_mysql), session, tokenizer, xml, xmlreader, xmlwriter, and zip. The test runner also needs its usual DOM/XML/mbstring support. `pcntl` is useful for development workers on Linux.
- SQLite for the simplest local setup. MySQL 8 is the intended production database, but is not yet verified in this environment.
- A modern browser. Microphone access needs HTTPS or the browser's localhost secure-context exception.

Redis, Reverb, LiveKit, Docker, and a telephony account are not prerequisites for this milestone.

## Automated fresh server installation

On a clean Ubuntu 22.04 or 24.04 LTS server:

```sh
sudo ./bin/install.sh
```

This automated installer verifies PHP, Composer, Node.js, and Python 3.11+, provisions the Python virtual environment (`voice-worker/.venv`), installs dependencies, runs non-destructive migrations, builds production Vite assets, and runs `php artisan voca:doctor`.

For complete production server deployment instructions including Nginx, SSL, and systemd services, see [DEPLOYMENT.md](DEPLOYMENT.md). For operational troubleshooting, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).

## Local development setup

From the repository root:

```sh
composer run setup
php artisan serve --host=0.0.0.0 --port=8000
```

The setup script:

1. Installs the exact Composer lockfile.
2. Copies `.env.example` to `.env` only when missing.
3. Runs `php artisan voca:install`: preserves an existing application key, generates a key only if blank, creates the configured SQLite file if needed, and runs non-destructive migrations.
4. Installs the locked npm dependencies and builds assets.

Open the site and register your own account. Each registration creates an isolated business workspace and owner membership. No default admin password or shared demo account exists. Do not share a user's account as a substitute for organization membership management.

### Individual commands, if preferred

```sh
composer install
cp .env.example .env          # Only on the first installation; never overwrite an existing .env.
php artisan voca:install
npm ci
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

The `cp` step is deliberately not part of an update procedure. Never regenerate a working installation's encryption key to fix an unrelated error.

### Development with frontend rebuilding

```sh
composer run dev
```

This runs Laravel on all interfaces, a database queue listener, and Vite. The queue listener is a development convenience; the product correctly does not claim a verified production heartbeat from its mere existence.

For a shared/proxied preview, **built assets plus the Laravel server are recommended**. They are same-origin and require no browser connection to a sandbox's `localhost`. Vite allows the preview host suffix `.e2b.app`; other remote HMR deployments may need an intentional HTTPS HMR host configuration. Never hardcode a browser API URL to `127.0.0.1` to reach a remote backend.

## Environment configuration

`.env.example` contains no real credentials.

| Variable                                          | Purpose                                                                       |
| ------------------------------------------------- | ----------------------------------------------------------------------------- |
| APP_NAME / APP_URL                                | Product name and public URL; use the actual HTTPS URL when deploying          |
| APP_KEY                                           | Laravel encryption key; preserve and back up securely                         |
| APP_DEBUG                                         | Keep false on shared/public hosts                                             |
| DB_CONNECTION                                     | sqlite locally; mysql for a properly provisioned MySQL installation           |
| DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD | Server-only database connection settings when using MySQL                     |
| SESSION_DRIVER / SESSION_ENCRYPT                  | Database sessions encrypted by default                                        |
| SESSION_SECURE_COOKIE                             | Set true on an HTTPS deployment; false is for local HTTP                      |
| TRUSTED_PROXIES                                   | Actual trusted reverse-proxy IPs/CIDRs; default loopback only                 |
| CACHE_STORE / QUEUE_CONNECTION                    | Database-backed defaults, no Redis server needed                              |
| REDIS_*                                           | Optional future service settings, not evidence of a live Redis connection     |
| MAIL_MAILER                                       | Log transport locally; reset-email/verification workflows are not implemented |

For MySQL, configure the connection before running migrations. Do not treat running the schema on SQLite as a completed MySQL acceptance test.

Organization name, display timezone, and default draft language are managed through **Settings** in the application, not by editing PHP files. Provider keys, applicable project IDs, model/voice choices, and routing are now managed through **AI providers** in the web UI; no provider environment variables or PHP edits are required. Never put keys in agent instructions. See [PROVIDER_SETUP.md](PROVIDER_SETUP.md).

Provider-route unexpected exceptions are generic even if debug is mistakenly enabled, and bootstrap disables exception argument capture. These are defense-in-depth measures, not permission to enable public debug pages or raw request recording. Keep `APP_DEBUG=false` and host PHP `zend.exception_ignore_args=On`; disable/redact secret-bearing proxy/APM request headers/bodies. See [SECURITY_REVIEW.md](SECURITY_REVIEW.md) for the reviewed boundaries.

## Provider setup and optional platform operators

Workspace owners/admins use **AI providers**; members see redacted read-only details. Saving never calls a provider or marks the key connected. A real Test Connection needs correct read scopes, account eligibility, and reachable provider APIs; a successful catalog does not activate a voice agent.

No platform operator is seeded or self-granted by registration. If shared platform credentials are actually required, a trusted installation operator may explicitly provision an existing account:

```sh
php artisan voca:platform-admin owner@example.com
# Revoke access later:
php artisan voca:platform-admin owner@example.com --revoke
```

Confirm the prompt intentionally. The account then has a separately authorized `/app/platform/providers` screen, not arbitrary access to other organizations' secrets. These routes can define platform-wide backend defaults; treat that role as privileged and revoke when no longer needed. Ordinary tenant credentials do not require this step.

A shared persistent cache (database by default) is required for cross-request throttles and test locks in deployment. Do not use `CACHE_STORE=array` in production. Provider checks are synchronous bounded metadata requests; they need no queue, Redis, or audio worker.

## Reverse proxies and HTTPS

The application honors forwarded protocol/host information only from configured trusted proxies. Use explicit proxy addresses in production. The Arena preview's wildcard proxy trust is sandbox-only and is not checked into `.env.example`.

- Terminate TLS correctly and use secure session cookies.
- Forward the original host and protocol from your trusted proxy.
- Serve only `public/` as the document root. Never expose `.env`, source directories, private storage, or Composer credentials.
- Keep `APP_DEBUG=false`, set PHP `zend.exception_ignore_args=On`, and store server logs privately. Do not enable provider HTTP header/body logging. Redact Authorization, x-goog-api-key, xi-api-key, api_key, and credentials in any monitoring added later.
- Use PHP-FPM/Nginx, Apache, or another supported Laravel production server. `php artisan serve` is development-only.

## Background services, scheduler, and voice (Milestone 3)

Foundation operations do not require a long-running queue or scheduler job. A conventional queue worker can be started for development:

```sh
php artisan queue:work --tries=3
```

### LiveKit & Python Voice Worker Setup

Milestone 3 introduces the real-time browser voice engine:

1. **Configure Laravel `.env`:**
    ```ini
    VOICE_ENABLED=true
    LIVEKIT_URL=wss://your-livekit-server.example.com
    LIVEKIT_API_KEY=your-livekit-api-key
    LIVEKIT_API_SECRET=your-livekit-api-secret
    VOICE_WORKER_NAME=voca-browser-v1
    VOICE_WORKER_KEY_ID=v1
    VOICE_WORKER_SECRET=your-shared-hmac-secret-min-32-chars
    ```
2. **Configure and start the Python Worker (`voice-worker/`):**
    ```bash
    cd voice-worker
    cp .env.example .env
    # Populate LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET, VOCA_CONTROL_URL, VOICE_WORKER_SECRET
    python -m venv .venv
    source .venv/bin/activate
    pip install -r <(python -c "import tomllib; print('\n'.join(tomllib.load(open('pyproject.toml','rb'))['project']['dependencies']))")
    # Or install dependencies via uv / pip:
    pip install ruff pytest "livekit-agents[openai]==1.8.11"

    # Run the worker:
    python agent.py dev    # Local development
    python agent.py start  # Production daemon
    ```
3. **Run Session Housekeeping / Reaper:**
   Configure the Laravel scheduler in cron (`* * * * * cd /path-to-voca && php artisan schedule:run >> /dev/null 2>&1`), or manually invoke:
    ```sh
    php artisan voice:reap
    ```
    The reaper sweeps expired authority leases and ensures orphaned LiveKit rooms are cleanly deleted.

## Updating a working installation

Back up the database and encryption key first. Then install the new locked dependencies, build assets, and run migrations without replacing `.env` or rotating `APP_KEY`:

```sh
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
```

Use production maintenance and rollback procedures appropriate to your server. Do not run `migrate:fresh`, `db:wipe`, or destructive test commands against a live database.

## Production release gate

This foundation is **not cleared for production calling**. Before public production rollout, finish and verify provider/voice integrations, email verification and account recovery, organization/member administration, granular permissions/platform administration, security and load review, database/platform compatibility, legal disclosures, recording/consent controls, retention, backup/restore drills, and service monitoring.

The repository includes a GitHub Actions workflow for PHP checks and desktop/mobile Chromium tests. It has not been run remotely just because the workflow file exists.

## Backups and restoration

- Use a consistent database snapshot or SQLite's backup facilities; do not blindly copy a live SQLite database while writes are occurring.
- Back up `APP_KEY` separately and securely. Both encrypted sessions and stored provider credentials depend on it now. Never use key:generate as a repair on an existing installation.
- Include authorized private business files when document/recording storage is introduced.
- Restore into an isolated environment, apply migrations, and verify account and tenant isolation before switching traffic.
- No automated backup or restoration workflow is implemented in this milestone.

## Diagnostics and Upgrades

### System Diagnostics (`voca:doctor`)

Run the comprehensive application doctor command at any time:

```sh
php artisan voca:doctor
```

It safely checks PHP version and extensions, Composer vendor files, Vite build manifest, Python 3.11+ virtual environment, LiveKit worker dependencies, database connectivity, migration status, encryption key, LiveKit server connectivity, worker registration, and queue health without revealing secrets.

To output structured JSON for automated monitoring or health probes:

```sh
php artisan voca:doctor --json
```

### Safe Application Upgrades (`voca:upgrade`)

To safely upgrade an existing Voca installation:

```sh
php artisan voca:upgrade
```

Or run the upgrade shell script:

```sh
sudo ./bin/upgrade.sh
```

This automatically creates a timestamped database backup in `storage/backups/`, applies migrations non-destructively, refreshes application caches, rebuilds production assets, and verifies health via `voca:doctor`.

## Troubleshooting

- **PHP or Composer missing:** install the requirements above with your OS's trusted distribution. The sandbox was provisioned with an ignored, portable runtime; that development tooling is not part of the application distribution.
- **Missing encryption key:** on a new installation run `php artisan voca:install`. On an existing installation restore the correct key—do not rotate it casually.
- **Missing SQLite file:** `voca:install` creates the configured file if its parent directory exists and is writable. Check the database path, directory permissions, and PDO SQLite extension.
- **Vite manifest missing:** run `npm ci` and `npm run build` before opening the app.
- **Old HTTPS/host configuration:** verify trusted proxy settings and clear stale configuration with `php artisan optimize:clear` before rebuilding caches.
- **Workspace 403:** the active organization must have a real membership for the signed-in account. There is no organization-switching/invitation UI yet.
- **Microphone denied or busy:** allow access in browser site settings, connect an input device, close other audio applications, and use HTTPS. The lab currently checks the device only; it cannot connect to an AI.
- **Provider network error:** verify permitted outbound HTTPS/DNS/TLS/proxy settings. The sandbox blocks all four provider API hosts; this is not proof of a bad key. No placeholder success bypass exists.
- **Provider key could not be decrypted:** restore the original APP_KEY or replace the provider key in the UI, then recheck; no secret-reveal/unencrypted recovery path exists.
- **Stale provider configuration/catalog:** reload after concurrent edits, or run Test Connection again after 24 hours/key rotation. Unknown/incompatible IDs are deliberately not substituted.
- **Voice engine not configured / queue unverified:** expected for this release, not a simulated outage of an implemented voice service.
