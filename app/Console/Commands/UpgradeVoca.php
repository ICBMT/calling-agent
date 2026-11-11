<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpgradeVoca extends Command
{
    protected $signature = 'voca:upgrade {--skip-backup : Skip creating a database backup before migrations}';

    protected $description = 'Safely upgrade Voca application state, run migrations, and verify system health';

    public function handle(): int
    {
        $this->info('Starting Voca upgrade sequence...');

        // 1. Database backup (if SQLite)
        if (! $this->option('skip-backup') && config('database.default') === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            if (is_file($dbPath)) {
                $backupDir = storage_path('backups');
                if (! is_dir($backupDir)) {
                    @mkdir($backupDir, 0755, true);
                }
                $backupFile = $backupDir.'/voca-db-backup-'.now()->format('Ymd-His').'.sqlite';
                if (@copy($dbPath, $backupFile)) {
                    $this->info("✓ Database backup saved to: {$backupFile}");
                }
            }
        }

        // 2. Run migrations
        $this->info('Applying database migrations...');
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Migration step failed.');

            return self::FAILURE;
        }

        // 3. Clear stale caches
        $this->info('Refreshing application cache...');
        $this->call('optimize:clear');

        // 4. Run Doctor check
        $this->newLine();
        $this->info('Running post-upgrade diagnostics...');

        return $this->call('voca:doctor');
    }
}
