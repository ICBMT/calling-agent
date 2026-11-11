#!/usr/bin/env bash
set -eo pipefail

# Voca AI Calling Agent - Safe Production Upgrade Script

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "=========================================================="
echo "    Voca AI Calling Agent - Safe Application Upgrade      "
echo "=========================================================="
echo "Upgrading in directory: $ROOT_DIR"
echo ""

# 1. Run artisan upgrade (backs up database, runs migrations, clears caches)
echo "Executing framework upgrade and migration sequence..."
php artisan voca:upgrade

# 2. Update Composer dependencies if needed
echo ""
echo "Updating composer autoloader..."
composer install --prefer-dist --no-dev --no-interaction --optimize-autoloader

# 3. Update Python worker dependencies if needed
if [ -d "voice-worker/.venv" ]; then
    echo ""
    echo "Updating voice worker dependencies..."
    voice-worker/.venv/bin/pip install --upgrade "livekit-agents[openai]==1.8.11" "livekit-plugins-google==1.8.11" --quiet
fi

# 4. Rebuild frontend assets
echo ""
echo "Rebuilding frontend assets..."
npm ci --silent
npm run build

# 5. Clear and warm optimization caches
echo ""
echo "Optimizing application caches..."
php artisan optimize

# 6. Post-upgrade diagnostics
echo ""
echo "Running post-upgrade system doctor..."
php artisan voca:doctor

echo ""
echo "=========================================================="
echo "    Voca AI Upgrade Successfully Completed!               "
echo "=========================================================="
