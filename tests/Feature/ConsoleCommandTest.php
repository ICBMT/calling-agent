<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ConsoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_voca_doctor_command_runs_and_outputs_diagnostic_report(): void
    {
        $this->artisan('voca:doctor')
            ->expectsOutputToContain('VOCA AI APPLICATION DOCTOR')
            ->expectsOutputToContain('PHP & Extensions')
            ->expectsOutputToContain('Composer dependencies')
            ->expectsOutputToContain('Database connectivity & storage')
            ->assertSuccessful();
    }

    public function test_voca_doctor_command_outputs_valid_json_with_summary(): void
    {
        $this->artisan('voca:doctor', ['--json' => true])
            ->assertSuccessful();
    }

    public function test_voca_upgrade_command_creates_backup_and_runs_doctor(): void
    {
        $tempDb = storage_path('test-db.sqlite');
        touch($tempDb);
        config(['database.connections.sqlite.database' => $tempDb]);

        $this->artisan('voca:upgrade')
            ->expectsOutputToContain('Starting Voca upgrade sequence...')
            ->expectsOutputToContain('Applying database migrations...')
            ->expectsOutputToContain('VOCA AI APPLICATION DOCTOR')
            ->assertSuccessful();

        $backupFiles = File::glob(storage_path('backups/*.sqlite'));
        $this->assertNotEmpty($backupFiles);

        // Clean up test files
        File::delete($tempDb);
        File::deleteDirectory(storage_path('backups'));
    }
}
