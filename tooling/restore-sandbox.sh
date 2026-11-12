#!/usr/bin/env bash
# Rehydrates everything the snapshot system strips between sandbox sessions:
# repo node_modules, PHP vendor/ (via WASM composer substitute + shims),
# voice-worker venv, Vite production build, and the two tooling npm trees.
# Idempotent — safe to run on every session resume.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"

step() { printf '\n=== %s ===\n' "$1"; }

step "0/6 .env + APP_KEY (wiped with snapshots)"
if [ ! -f .env ]; then cp .env.example .env; fi
if ! grep -q "^APP_KEY=base64:" .env; then
    node tooling/sandbox-php/phpw.mjs artisan key:generate --force >/dev/null
fi

step "1/6 repo node deps"
npm ci --silent --no-audit --no-fund --loglevel=error

step "2/6 sandbox PHP deps (WASM binaries)"
npm --prefix tooling/sandbox-php ci --silent --no-audit --no-fund --loglevel=error

step "3/6 PHP vendor/ via mini-composer (codeload zipballs)"
python3 tooling/sandbox-php/mini_composer.py | tail -2
python3 tooling/sandbox-php/apply_wasm_shims.py

step "4/6 voice-worker venv"
if [ ! -x voice-worker/.venv/bin/python ]; then
    (python3.11 -m venv voice-worker/.venv 2>/dev/null || python3 -m venv voice-worker/.venv)
    voice-worker/.venv/bin/pip install -q -e voice-worker pytest==8.4.2 ruff==0.14.5 \
        'livekit-agents[openai]==1.8.11' 'livekit-plugins-google==1.8.11' 2>&1 | tail -1 || true
fi

step "5/6 Vite production build"
npm run build --silent

step "6/6 browser tooling (headless chromium, self-contained from npm)"
npm --prefix tooling/browser ci --silent --no-audit --no-fund --loglevel=error
node tooling/browser/extract-libs.mjs

printf '\nSandbox fully restored. Verify with:\n  npm run test:php:wasm\n  node tooling/browser/serve.mjs 8000 &\n  node tooling/browser/sweep.mjs\n'
