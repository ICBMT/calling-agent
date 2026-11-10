<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformAdmin extends Command
{
    protected $signature = 'voca:platform-admin {email : An existing account email} {--revoke : Remove platform provider access} {--force : Confirm without an interactive prompt}';

    protected $description = 'Explicitly provision/revoke a platform provider operator; never granted by registration';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', Str::lower($this->argument('email')))->first();
        if (! $user) {
            $this->error('No existing account matches. Register a workspace account first.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm(($this->option('revoke') ? 'Revoke' : 'Grant').' platform-wide provider management for this account?', false)) {
            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $audit): void {
            $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
            $audit->record(null, $user, $this->option('revoke') ? 'provider.platform_operator_revoked' : 'provider.platform_operator_granted', $user);
        });
        $this->info('Platform provider access '.($this->option('revoke') ? 'revoked.' : 'granted. Shared credentials can affect every organization; use least-privilege keys.'));

        return self::SUCCESS;
    }
}
