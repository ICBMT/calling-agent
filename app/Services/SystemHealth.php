<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\ProviderConfiguration;
use App\Services\Voice\LiveKitGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealth
{
    public function checks(?Organization $organization = null): array
    {
        try {
            DB::select('select 1');
            $database = ['label' => 'Database', 'status' => 'Connected', 'ok' => true, 'detail' => 'A live database query passed.'];
        } catch (Throwable) {
            $database = ['label' => 'Database', 'status' => 'Unavailable', 'ok' => false, 'detail' => 'Check the server database configuration.'];
        }

        $writable = is_writable(storage_path('app/private'));

        // 1. Genuine Queue worker heartbeat check
        $queueHeartbeat = Cache::get('system:queue_heartbeat');
        $queueOk = is_numeric($queueHeartbeat) && (time() - (int) $queueHeartbeat) <= 60;
        $queueCheck = [
            'label' => 'Queue worker',
            'status' => $queueOk ? 'Running' : ($queueHeartbeat ? 'Stalled' : 'Not verified'),
            'ok' => $queueOk,
            'detail' => $queueOk
                ? 'Worker heartbeat received within the last 60 seconds.'
                : ($queueHeartbeat ? 'Worker heartbeat is older than 60 seconds.' : 'Worker heartbeat not verified. Start php artisan queue:work.'),
        ];

        // 2. Genuine LiveKit server connectivity and authentication check
        $gateway = app(LiveKitGateway::class);
        $livekitHealth = $gateway->checkHealth();
        $voiceEngineCheck = [
            'label' => 'Voice engine',
            'status' => $livekitHealth['status'],
            'ok' => $livekitHealth['ok'],
            'detail' => $livekitHealth['detail'],
        ];

        // 3. Genuine Python voice worker liveness and LiveKit registration check
        $workerHealth = $gateway->checkWorkerHealth();
        $voiceWorkerCheck = [
            'label' => 'Voice worker',
            'status' => $workerHealth['status'],
            'ok' => $workerHealth['ok'],
            'detail' => $workerHealth['detail'],
        ];

        $configurations = $organization ? ProviderConfiguration::query()->forScope($organization)->get() : collect();
        $verified = $configurations->filter(fn ($configuration) => $configuration->freshCatalog())->count();

        return [
            $database,
            ['label' => 'Private storage', 'status' => $writable ? 'Writable' : 'Unavailable', 'ok' => $writable, 'detail' => 'Local private storage permission check.'],
            $queueCheck,
            $voiceEngineCheck,
            $voiceWorkerCheck,
            ['label' => 'Provider authentication', 'status' => $verified ? $verified.' recently verified' : ($configurations->isNotEmpty() ? 'Needs check' : 'Not configured'), 'ok' => $verified > 0, 'detail' => $configurations->count().' workspace configurations saved. Only real checks within 24 hours count; this is not audio/voice readiness.'],
            ['label' => 'Telephony', 'status' => 'Not connected', 'ok' => false, 'detail' => 'A verified telephony adapter and media gateway are a later milestone.'],
        ];
    }
}
