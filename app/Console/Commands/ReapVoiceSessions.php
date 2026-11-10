<?php

namespace App\Console\Commands;

use App\Services\Voice\VoiceSessions;
use Illuminate\Console\Command;

class ReapVoiceSessions extends Command
{
    protected $signature = 'voice:reap {--limit=100 : Maximum active and cleanup records per sweep (1–100)}';

    protected $description = 'Expire/revoke stale browser sessions, retry LiveKit room cleanup, and prune old safe metadata/nonces';

    public function handle(VoiceSessions $sessions): int
    {
        $counts = $sessions->sweep(max(1, min(100, (int) $this->option('limit'))));
        $this->info('Browser sessions closed: '.$counts['closed'].'. Rooms confirmed cleaned: '.$counts['cleaned'].'.');
        $this->line('Unconfirmed cleanup stays pending for a later sweep. This command is not a voice-readiness check.');

        return self::SUCCESS;
    }
}
