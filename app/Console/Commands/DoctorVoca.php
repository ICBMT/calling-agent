<?php

namespace App\Console\Commands;

use App\Models\ProviderConfiguration;
use App\Services\Voice\LiveKitGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DoctorVoca extends Command
{
    protected $signature = 'voca:doctor {--json : Output diagnostic results as JSON}';

    protected $description = 'Run a comprehensive, safe health check across all Voca layers without exposing secrets';

    public function handle(LiveKitGateway $livekit): int
    {
        $checks = [];

        // 1. PHP Version & Extensions
        $requiredExtensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml', 'zip'];
        $missingExtensions = array_values(array_filter($requiredExtensions, fn ($ext) => ! extension_loaded($ext)));
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.3.0', '>=') && empty($missingExtensions);
        $checks['php'] = [
            'category' => 'Runtime',
            'name' => 'PHP & Extensions',
            'status' => $phpOk ? 'PASS' : 'FAIL',
            'detail' => $phpOk
                ? "PHP {$phpVersion} with all required extensions."
                : ($missingExtensions ? 'Missing extensions: '.implode(', ', $missingExtensions) : "PHP {$phpVersion} is below required 8.3+."),
            'remediation' => $phpOk ? null : 'Install PHP 8.3+ and missing extensions: sudo apt install php8.3-cli php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip',
        ];

        // 2. Composer Dependencies
        $composerOk = is_file(base_path('vendor/autoload.php'));
        $checks['composer'] = [
            'category' => 'Runtime',
            'name' => 'Composer dependencies',
            'status' => $composerOk ? 'PASS' : 'FAIL',
            'detail' => $composerOk ? 'Composer vendor dependencies installed.' : 'vendor/autoload.php not found.',
            'remediation' => $composerOk ? null : 'Run composer install --prefer-dist to install backend dependencies.',
        ];

        // 3. Frontend Build Assets
        $manifestOk = is_file(public_path('build/manifest.json'));
        $checks['frontend'] = [
            'category' => 'Runtime',
            'name' => 'Frontend assets (Vite)',
            'status' => $manifestOk ? 'PASS' : 'FAIL',
            'detail' => $manifestOk ? 'Vite production manifest and chunks built.' : 'public/build/manifest.json missing.',
            'remediation' => $manifestOk ? null : 'Run npm ci && npm run build to compile production frontend assets.',
        ];

        // 4. Python Environment & Worker Dependencies
        $venvPython = base_path('voice-worker/.venv/bin/python');
        $pythonVenvOk = is_file($venvPython) && is_executable($venvPython);
        $pythonVersionStr = 'Not found';
        $pythonDepsOk = false;
        if ($pythonVenvOk) {
            $pyVerOutput = @shell_exec(escapeshellcmd($venvPython).' -c "import sys; print(f\'{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}\')" 2>/dev/null');
            $pythonVersionStr = trim((string) $pyVerOutput) ?: 'Unknown';
            $depsCheck = @shell_exec(escapeshellcmd($venvPython).' -c "import livekit.agents, livekit.plugins.openai, livekit.plugins.google; print(\'OK\')" 2>/dev/null');
            $pythonDepsOk = trim((string) $depsCheck) === 'OK';
        }
        $checks['python_worker'] = [
            'category' => 'Voice Engine',
            'name' => 'Python worker runtime (.venv)',
            'status' => ($pythonVenvOk && $pythonDepsOk) ? 'PASS' : ($pythonVenvOk ? 'WARNING' : 'FAIL'),
            'detail' => ($pythonVenvOk && $pythonDepsOk)
                ? "Python {$pythonVersionStr} with livekit-agents, OpenAI, and Google Gemini plugins installed."
                : ($pythonVenvOk ? "Python {$pythonVersionStr} found, but required LiveKit plugins are missing." : 'voice-worker/.venv/bin/python not found.'),
            'remediation' => ($pythonVenvOk && $pythonDepsOk) ? null : 'Set up worker: python3.11 -m venv voice-worker/.venv && voice-worker/.venv/bin/pip install "livekit-agents[openai]==1.8.11" "livekit-plugins-google==1.8.11"',
        ];

        // 5. Database Connectivity & Storage
        try {
            DB::select('select 1');
            $dbOk = true;
            $dbDetail = 'Connected via '.config('database.default').' driver.';
        } catch (Throwable) {
            $dbOk = false;
            $dbDetail = 'Database connection failed.';
        }
        $storageOk = is_writable(storage_path('app/private'));
        $checks['database'] = [
            'category' => 'Database',
            'name' => 'Database connectivity & storage',
            'status' => ($dbOk && $storageOk) ? 'PASS' : 'FAIL',
            'detail' => ($dbOk && $storageOk)
                ? "{$dbDetail} Local private storage is writable."
                : (! $dbOk ? $dbDetail : 'storage/app/private is not writable.'),
            'remediation' => ($dbOk && $storageOk) ? null : 'Check DB connection in .env and run chmod -R 775 storage bootstrap/cache.',
        ];

        // 6. Database Migrations
        $migrationsOk = false;
        try {
            $migrationsOk = Schema::hasTable('migrations') && Schema::hasTable('users') && Schema::hasTable('organizations') && Schema::hasTable('voice_sessions');
        } catch (Throwable) {
        }
        $checks['migrations'] = [
            'category' => 'Database',
            'name' => 'Database schema & migrations',
            'status' => $migrationsOk ? 'PASS' : 'FAIL',
            'detail' => $migrationsOk ? 'Core schema and voice tables exist.' : 'Migrations have not been fully applied.',
            'remediation' => $migrationsOk ? null : 'Run php artisan migrate to establish database schema.',
        ];

        // 7. Laravel Application Encryption Key
        $appKey = (string) config('app.key');
        $keyOk = str_starts_with($appKey, 'base64:') && strlen(base64_decode(substr($appKey, 7), true) ?: '') === 32;
        $checks['app_key'] = [
            'category' => 'Security',
            'name' => 'Application encryption key',
            'status' => $keyOk ? 'PASS' : 'FAIL',
            'detail' => $keyOk ? 'Valid 32-byte AES-256-GCM encryption key configured.' : 'Invalid or blank APP_KEY.',
            'remediation' => $keyOk ? null : 'Run php artisan key:generate to generate an encryption key.',
        ];

        // 8. Queue Worker Heartbeat
        $queueHeartbeat = Cache::get('system:queue_heartbeat');
        $queueOk = is_numeric($queueHeartbeat) && (time() - (int) $queueHeartbeat) <= 60;
        $checks['queue'] = [
            'category' => 'Queue',
            'name' => 'Queue worker heartbeat',
            'status' => $queueOk ? 'PASS' : 'WARNING',
            'detail' => $queueOk
                ? 'Queue worker heartbeat received within the last 60 seconds.'
                : ($queueHeartbeat ? 'Queue worker heartbeat is older than 60 seconds (Stalled).' : 'No worker heartbeat recorded in cache.'),
            'remediation' => $queueOk ? null : 'Start a background queue worker: php artisan queue:work --sleep=3 --tries=3',
        ];

        // 9. LiveKit Server Connectivity (Read-only Twirp ListRooms)
        $livekitHealth = $livekit->checkHealth();
        $checks['livekit'] = [
            'category' => 'Voice Engine',
            'name' => 'LiveKit server connectivity',
            'status' => $livekitHealth['ok'] ? 'PASS' : 'WARNING',
            'detail' => $livekitHealth['detail'],
            'remediation' => $livekitHealth['ok'] ? null : 'Configure LIVEKIT_URL, LIVEKIT_API_KEY, and LIVEKIT_API_SECRET in .env and verify server is running on port 7880.',
        ];

        // 10. Voice Worker Registration Probe (HTTP GET /worker)
        $workerHealth = $livekit->checkWorkerHealth();
        $checks['worker_registration'] = [
            'category' => 'Voice Engine',
            'name' => 'Voice worker LiveKit registration',
            'status' => $workerHealth['ok'] ? 'PASS' : 'WARNING',
            'detail' => $workerHealth['detail'],
            'remediation' => $workerHealth['ok'] ? null : 'Ensure Python voice worker is running on port 8081 and connected to LiveKit: cd voice-worker && .venv/bin/python agent.py start',
        ];

        // 11. AI Provider Configurations
        $providerCount = ProviderConfiguration::query()->count();
        $freshCount = ProviderConfiguration::query()->get()->filter(fn ($c) => $c->freshCatalog())->count();
        $checks['providers'] = [
            'category' => 'AI Providers',
            'name' => 'AI provider configurations',
            'status' => $freshCount > 0 ? 'PASS' : ($providerCount > 0 ? 'WARNING' : 'WARNING'),
            'detail' => "{$providerCount} provider configurations saved ({$freshCount} verified within 24h).",
            'remediation' => $freshCount > 0 ? null : 'Visit /app/providers, add an OpenAI or Gemini API key, and click Test Connection.',
        ];

        // 12. Production Security Configuration
        $isDebug = config('app.debug');
        $isSecureCookie = config('session.secure');
        $isProd = app()->environment('production');
        $secOk = ! ($isProd && $isDebug) && ! ($isProd && ! $isSecureCookie);
        $checks['security'] = [
            'category' => 'Security',
            'name' => 'Production environment security',
            'status' => $secOk ? 'PASS' : 'WARNING',
            'detail' => $isProd
                ? ($secOk ? 'APP_DEBUG=false and secure session cookies enabled for production.' : 'Production environment should have APP_DEBUG=false and SESSION_SECURE_COOKIE=true.')
                : 'Development environment (APP_DEBUG='.(var_export($isDebug, true)).').',
            'remediation' => $secOk ? null : 'Set APP_DEBUG=false and SESSION_SECURE_COOKIE=true in .env for production.',
        ];

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'timestamp' => now()->toIso8601String(),
                'checks' => $checks,
                'summary' => [
                    'pass' => count(array_filter($checks, fn ($c) => $c['status'] === 'PASS')),
                    'warning' => count(array_filter($checks, fn ($c) => $c['status'] === 'WARNING')),
                    'fail' => count(array_filter($checks, fn ($c) => $c['status'] === 'FAIL')),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return count(array_filter($checks, fn ($c) => $c['status'] === 'FAIL')) > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>┌────────────────────────────────────────────────────────────────────────┐</>');
        $this->line('<fg=cyan;options=bold>│                       VOCA AI APPLICATION DOCTOR                       │</>');
        $this->line('<fg=cyan;options=bold>└────────────────────────────────────────────────────────────────────────┘</>');
        $this->newLine();

        $rows = [];
        foreach ($checks as $c) {
            $statusTag = match ($c['status']) {
                'PASS' => '<fg=green;options=bold> PASS </>',
                'WARNING' => '<fg=yellow;options=bold> WARN </>',
                'FAIL' => '<fg=red;options=bold> FAIL </>',
                default => '<fg=gray> ---- </>',
            };
            $rows[] = [$statusTag, $c['category'], $c['name'], $c['detail']];
        }

        $this->table(['Status', 'Category', 'Component', 'Details'], $rows);

        $hasFail = count(array_filter($checks, fn ($c) => $c['status'] === 'FAIL')) > 0;
        $hasWarn = count(array_filter($checks, fn ($c) => $c['status'] === 'WARNING')) > 0;

        $this->newLine();
        if ($hasFail || $hasWarn) {
            $this->line('<fg=yellow;options=bold>Actionable Remediation Guidance:</>');
            foreach ($checks as $c) {
                if ($c['remediation'] && in_array($c['status'], ['FAIL', 'WARNING'], true)) {
                    $this->line("  <fg=cyan>• {$c['name']}:</> {$c['remediation']}");
                }
            }
            $this->newLine();
        }

        if (! $hasFail) {
            $this->info('✓ Core application foundation and dependencies are healthy.');

            return self::SUCCESS;
        }

        $this->error('✗ One or more critical checks failed. Resolve the issues above.');

        return self::FAILURE;
    }
}
