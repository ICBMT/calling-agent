<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InstallVoca extends Command
{
    protected $signature = 'voca:install {--force : Run migrations without the production confirmation}';

    protected $description = 'Prepare Voca without replacing an existing application encryption key';

    public function handle(): int
    {
        if (! is_file($this->laravel->environmentFilePath())) {
            $this->error('Create .env from .env.example first, then run this command again.');

            return self::FAILURE;
        }

        if (blank(config('app.key'))) {
            if ($this->call('key:generate', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $this->info('Existing application key preserved.');
        }

        if (config('database.default') === 'sqlite') {
            $path = config('database.connections.sqlite.database');
            if ($path !== ':memory:' && ! is_file($path)) {
                if (! is_dir(dirname($path)) || ! touch($path)) {
                    $this->error('The SQLite database directory must exist and be writable.');

                    return self::FAILURE;
                }
            }
        }

        return $this->call('migrate', ['--force' => (bool) $this->option('force')]);
    }
}
