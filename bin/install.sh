#!/usr/bin/env bash
set -eo pipefail

# Voca AI Calling Agent - Automated Installation Script
# Supports: Ubuntu 22.04 / 24.04 LTS, Debian 12, or local Linux machine

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "=========================================================="
echo "    Voca AI Calling Agent - Production Server Installer   "
echo "=========================================================="
echo "Installing in directory: $ROOT_DIR"
echo ""

# 1. Check Root / User
if [ "$(id -u)" -eq 0 ]; then
    echo "Notice: Running as root. Application files will be set up for current directory."
fi

# 2. Check System Prerequisites
echo "Checking system dependencies..."
command -v php >/dev/null 2>&1 || { echo >&2 "Error: PHP is not installed. Please install PHP 8.3 or 8.4."; exit 1; }
PHP_VER=$(php -r "echo PHP_VERSION;")
echo "Found PHP version: $PHP_VER"

command -v composer >/dev/null 2>&1 || { echo >&2 "Error: Composer is not installed."; exit 1; }
echo "Found Composer: $(composer --version | head -n 1)"

command -v node >/dev/null 2>&1 || { echo >&2 "Error: Node.js is not installed. Please install Node 22+."; exit 1; }
command -v npm >/dev/null 2>&1 || { echo >&2 "Error: npm is not installed."; exit 1; }
echo "Found Node: $(node -v) and npm: $(npm -v)"

command -v python3 >/dev/null 2>&1 || { echo >&2 "Error: Python 3 is not installed. Please install Python 3.11+."; exit 1; }
echo "Found Python: $(python3 --version)"

# 3. Environment File
echo ""
echo "Setting up application environment (.env)..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "Created .env from .env.example"
else
    echo "Existing .env preserved."
fi

# 4. Backend Dependencies
echo ""
echo "Installing Composer dependencies..."
composer install --prefer-dist --no-interaction --optimize-autoloader

# 5. Database & Application Keys
echo ""
echo "Initializing application and database..."
php artisan voca:install --force

# 6. Python Voice Worker Virtual Environment
echo ""
echo "Setting up Python Voice Worker (.venv)..."
if [ ! -d "voice-worker/.venv" ]; then
    python3 -m venv voice-worker/.venv
    echo "Created voice-worker/.venv"
fi

echo "Installing Python LiveKit worker dependencies..."
voice-worker/.venv/bin/python -m pip install --upgrade pip --quiet
voice-worker/.venv/bin/pip install "livekit-agents[openai]==1.8.11" "livekit-plugins-google==1.8.11" --quiet

if [ ! -f "voice-worker/.env" ]; then
    if [ -f "voice-worker/.env.example" ]; then
        cp voice-worker/.env.example voice-worker/.env
        echo "Created voice-worker/.env from example"
    fi
fi

# 7. Frontend Assets
echo ""
echo "Building production frontend assets with Vite..."
npm ci --silent
npm run build

# 8. Set Directory Permissions
echo ""
echo "Setting storage and cache permissions..."
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# 9. Verify Installation with Doctor
echo ""
echo "Running system diagnostics..."
php artisan voca:doctor

echo ""
echo "=========================================================="
echo "    Voca AI Installation Complete!                        "
echo "=========================================================="
echo "Next steps:"
echo "1. Configure LIVEKIT_URL, LIVEKIT_API_KEY, and LIVEKIT_API_SECRET in .env"
echo "2. Start the queue worker: php artisan queue:work"
echo "3. Start the Python voice worker: cd voice-worker && .venv/bin/python agent.py start"
echo "4. Or install systemd services from deploy/systemd/"
echo "5. Log in at http://your-server-ip:8000 and configure your AI providers."
echo "=========================================================="
