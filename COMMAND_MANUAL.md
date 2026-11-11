# Voca Command Manual — Numbered Terminal Setup Guide

**Purpose:** take a machine (Ubuntu/Debian Linux server, local Linux, or a laptop) from zero to a
**working browser voice conversation in the Playground**, using one numbered command at a time.
Run the steps **in order** — later numbers assume earlier ones succeeded.

> Four separate processes must be running for a live voice test. Keep each one in its own
> terminal window (development) or use the systemd services from Part 7 (production):
>
> | Process              | Default port                | Started by step |
> | -------------------- | --------------------------- | --------------- |
> | Laravel web app      | 8000 (HTTP)                 | 21              |
> | Laravel queue worker | — (background)              | 22              |
> | LiveKit media server | 7880, 7881, 50000–50010/udp | 23              |
> | Python voice worker  | 8081 (health)               | 24              |
>
> Optional: the scheduler (`voice:reap` cleanup) — step 25.

---

## Part 0 — System prerequisites (once per machine)

**1.** Update package lists:

```bash
sudo apt update && sudo apt -y upgrade
```

**2.** Install base tools:

```bash
sudo apt -y install git curl unzip ca-certificates lsb-release gnupg
```

**3.** Install **PHP 8.3+** (8.4 is the verified development runtime) with the extensions Voca needs:

```bash
sudo add-apt-repository -y ppa:ondrej/php        # only if your apt has no PHP 8.3+
sudo apt update
sudo apt -y install php8.4-cli php8.4-sqlite3 php8.4-mysql php8.4-mbstring \
    php8.4-xml php8.4-curl php8.4-bcmath php8.4-zip php8.4-intl php8.4-fileinfo
php -v    # must print 8.3 or newer
```

**4.** Install **Composer**:

```bash
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

**5.** Install **Node.js 22+** (this repository pins `22.22.3` in `.nvmrc`):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt -y install nodejs
node -v && npm -v
```

**6.** Install **Python 3.11+** and venv support:

```bash
sudo apt -y install python3 python3-venv python3-pip python3-dev build-essential
python3 --version    # must print 3.11, 3.12 or 3.13
```

---

## Part 1 — Install the Laravel application

**7.** Get the code (skip if you already have the repository):

```bash
git clone https://github.com/ICBMT/calling-agent.git
cd calling-agent
```

**8.** Run the one-command installer (installs PHP + JS dependencies, creates `.env`,
creates the SQLite database, generates `APP_KEY` only if missing, runs migrations, builds the frontend):

```bash
composer run setup
```

> The default database is a local SQLite file — nothing else to install for development.
> For MySQL/PostgreSQL, edit `DB_*` in `.env` afterwards and re-run `php artisan migrate`.

**9.** Verify the framework answers:

```bash
php artisan --version
php artisan voca:doctor --json | head -n 40    # full detail comes later, in step 26
```

`voca:doctor` check 1–8 (PHP, dependencies, frontend build, database, migrations, APP_KEY)
should already PASS. LiveKit/worker/provider checks stay WARNING until Part 2–5 are done —
that is expected and honest, not an error.

---

## Part 2 — Install and start the LiveKit media server

LiveKit is the realtime audio transport between the browser and the Python worker.

**10.** Install the LiveKit server binary:

```bash
curl -sSL https://get.livekit.io/cli | sudo bash   # installs the lk CLI
# server binary:
curl -sSL https://get.livekit.io | sudo bash
livekit-server --version
```

**11.** Create `livekit.yaml` in the repository root with a **local development key pair**.
Generate a strong secret first, then paste it into the file:

```bash
openssl rand -hex 24
```

```yaml
# livekit.yaml — LOCAL DEVELOPMENT ONLY
port: 7880
rtc:
    tcp_port: 7881
    port_range_start: 50000
    port_range_end: 50010
    use_external_ip: false
keys:
    voca-local-dev: PASTE_THE_48_HEX_CHARACTERS_FROM_THE_COMMAND_ABOVE
```

> The LiveKit API secret must be at least 32 characters or the application refuses to use it —
> the built-in `livekit-server --dev` keys (`devkey`/`secret`) are too short on purpose.

**12.** Start LiveKit in its own terminal (keep it running):

```bash
livekit-server --config livekit.yaml
```

---

## Part 3 — Connect Laravel to LiveKit and the voice worker

Two environment files must agree with each other:
`.env` (Laravel) and `voice-worker/.env` (Python worker). Fill them in this order.

**13.** Generate the shared worker HMAC secret (use it twice — Laravel **and** worker):

```bash
php -r "echo 'VOICE_WORKER_SECRET=' . bin2hex(random_bytes(32)) . PHP_EOL;"
```

**14.** Edit the Laravel `.env` voice section (development values):

```ini
VOICE_ENABLED=true
LIVEKIT_URL=ws://127.0.0.1:7880
LIVEKIT_API_KEY=voca-local-dev
LIVEKIT_API_SECRET=<the 48-hex secret from step 11>
VOICE_WORKER_NAME=voca-browser-v1
VOICE_WORKER_KEY_ID=v1
VOICE_WORKER_SECRET=<the value printed by step 13>
VOICE_WORKER_HEALTH_URL=http://127.0.0.1:8081
VOICE_ALLOW_INSECURE_LOCAL=true
```

> `VOICE_ALLOW_INSECURE_LOCAL=true` + `ws://127.0.0.1` are for your own machine only.
> On any server, use `wss://your-livekit-domain` and keep the flag `false`.

**15.** Create the worker environment file:

```bash
cp voice-worker/.env.example voice-worker/.env
```

**16.** Edit `voice-worker/.env` so it mirrors Laravel exactly:

```ini
LIVEKIT_URL=ws://127.0.0.1:7880
LIVEKIT_API_KEY=voca-local-dev
LIVEKIT_API_SECRET=<same 48-hex secret as step 11/14>
VOICE_WORKER_NAME=voca-browser-v1
VOCA_CONTROL_URL=http://127.0.0.1:8000
VOICE_WORKER_KEY_ID=v1
VOICE_WORKER_SECRET=<identical to Laravel's VOICE_WORKER_SECRET from step 13>
VOICE_ALLOW_INSECURE_LOCAL=true
```

| Setting             | Laravel `.env`                                | `voice-worker/.env` | Must match?    |
| ------------------- | --------------------------------------------- | ------------------- | -------------- |
| LiveKit URL         | `LIVEKIT_URL`                                 | `LIVEKIT_URL`       | ✅ identical   |
| LiveKit credentials | `LIVEKIT_API_KEY/SECRET`                      | same                | ✅ identical   |
| Worker name         | `VOICE_WORKER_NAME`                           | `VOICE_WORKER_NAME` | ✅ identical   |
| Worker HMAC         | `VOICE_WORKER_KEY_ID` + `VOICE_WORKER_SECRET` | same                | ✅ identical   |
| Laravel origin      | `APP_URL`                                     | `VOCA_CONTROL_URL`  | ✅ same origin |
| Insecure local flag | `VOICE_ALLOW_INSECURE_LOCAL`                  | same                | ✅ identical   |

**17.** Create the Python virtual environment and install the worker:

```bash
cd voice-worker
python3 -m venv .venv
./.venv/bin/pip install -e .
cd ..
```

**18.** Sanity-check the worker configuration (fails loudly on a bad env):

```bash
voice-worker/.venv/bin/python voice-worker/agent.py --help >/dev/null && echo "worker CLI OK"
```

**19.** Clear the Laravel config cache so the new values load:

```bash
php artisan config:clear
```

**20.** Verify credentials format checks pass locally:

```bash
php artisan voca:doctor   # LiveKit/worker checks turn PASS only after steps 21-24 are running
```

---

## Part 4 — Run the four development processes

Open **four** terminals (or tmux panes) at the repository root:

**21.** Terminal 1 — web application:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

**22.** Terminal 2 — queue worker (heartbeat feeding the Setup page/doctor check):

```bash
php artisan queue:work --sleep=3 --tries=3
```

**23.** Terminal 3 — LiveKit (skip if already running from step 12):

```bash
livekit-server --config livekit.yaml
```

**24.** Terminal 4 — Python voice worker (`dev` auto-reloads; use `start` in production):

```bash
cd voice-worker && ./.venv/bin/python agent.py dev
```

You should see the worker register with LiveKit. Leave all four running.

**25.** (Recommended) Terminal 5 — scheduled cleanup of stale/expired voice sessions:

```bash
php artisan schedule:work
```

---

## Part 5 — Verify the whole installation

**26.** Run the system diagnostic — every line should eventually read PASS:

```bash
php artisan voca:doctor
```

Fix anything marked WARNING/FAIL using the remediation text it prints (that is its purpose).

**27.** Run the automated test suites (optional but recommended after any change):

```bash
php artisan test                                        # PHP backend suite
voice-worker/.venv/bin/python -m pytest voice-worker/tests -q   # Python worker suite
npm run test:unit                                       # JavaScript voice suites
npm run build                                           # production frontend build
```

---

## Part 6 — First real browser voice test (UI steps, no terminal)

With all four processes running and `voca:doctor` green:

**28.** Open `http://<server-ip-or-localhost>:8000/register` and create your account
(a private workspace is created automatically).

**29.** Go to **Providers → Add provider** and save an **OpenAI** or **Google Gemini** API key,
then press **Test Connection**. Only a genuine API round-trip counts as verified.

**30.** After a successful test, pick a **realtime model and voice** from the discovered catalog
and save it as the realtime default.

**31.** Go to **Agents → New agent**, fill in name/purpose/instructions, and save a draft.

**32.** Go to **Playground**. Select your agent, provider, model, voice, and language, tick the
consent box, and allow microphone access when the browser asks.

**33.** Press **Start voice session** and speak. You should hear the AI answer, see the live
transcript stream, and watch real WebRTC + worker telemetry. **Interrupt AI** cancels speech;
**End session** closes everything (microphone released immediately).

**34.** If anything fails, the on-screen blocker/error message names the exact layer. Cross-check
with `php artisan voca:doctor` and see `TROUBLESHOOTING.md`.

---

## Part 7 — Production path (Ubuntu server)

Development terminals do not survive reboots. On a real server use the shipped automation:

**35.** One-command installer (checks everything in Parts 0–3 and wires services):

```bash
sudo bash bin/install.sh
```

**36.** Enable the long-running services (definitions in `deploy/systemd/`):

```bash
sudo systemctl enable --now voca-web voca-queue voca-voice-worker livekit
sudo systemctl status voca-web voca-queue voca-voice-worker livekit
```

**37.** Put Nginx + TLS in front using `deploy/nginx/voca.conf`, set `APP_URL`,
`SESSION_SECURE_COOKIE=true`, and `wss://` LiveKit in `.env`; see `DEPLOYMENT.md`.

**38.** Add the scheduler cron (session reaping):

```bash
(crontab -l 2>/dev/null; echo '* * * * * cd /path/to/calling-agent && php artisan schedule:run >> /dev/null 2>&1') | crontab -
```

**39.** For updates later — safe upgrade (DB backup, migrations, rebuild, doctor):

```bash
sudo bash bin/upgrade.sh
```

**40.** After any upgrade or reboot, re-verify:

```bash
php artisan voca:doctor
```

---

## Quick symptom → cure map

| Symptom                                  | First things to check                                            |
| ---------------------------------------- | ---------------------------------------------------------------- |
| Playground shows blockers                | `voca:doctor` output; steps 13–20 env values; provider test (29) |
| `worker_timeout` when starting a session | Terminal 4 worker alive? `VOICE_WORKER_NAME`/secrets identical?  |
| `not_configured`                         | `VOICE_ENABLED=true`, LiveKit URL + 32+ char secrets in `.env`   |
| WebRTC connects then drops               | LiveKit firewall: open 7881 TCP + 50000–50010 UDP                |
| Provider test fails                      | Key validity + server-side key restriction (no browser-only key) |
| `Queue worker: Not verified` on Setup    | Step 22 running? Cache store reachable?                          |

Full details: `INSTALLATION.md`, `DEPLOYMENT.md`, `TROUBLESHOOTING.md`, `PROVIDER_SETUP.md`.
