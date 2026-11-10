<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\ProviderConfiguration;
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

        $configurations = $organization ? ProviderConfiguration::query()->forScope($organization)->get() : collect();
        $verified = $configurations->filter(fn ($configuration) => $configuration->freshCatalog())->count();

        return [
            $database,
            ['label' => 'Private storage', 'status' => $writable ? 'Writable' : 'Unavailable', 'ok' => $writable, 'detail' => 'Local private storage permission check.'],
            ['label' => 'Queue worker', 'status' => 'Not verified', 'ok' => false, 'detail' => 'Worker heartbeat verification is a later milestone.'],
            ['label' => 'Voice engine', 'status' => 'Not configured', 'ok' => false, 'detail' => 'LiveKit and a voice worker are required in the voice milestone.'],
            ['label' => 'Provider authentication', 'status' => $verified ? $verified.' recently verified' : ($configurations->isNotEmpty() ? 'Needs check' : 'Not configured'), 'ok' => $verified > 0, 'detail' => $configurations->count().' workspace configurations saved. Only real checks within 24 hours count; this is not audio/voice readiness.'],
            ['label' => 'Telephony', 'status' => 'Not connected', 'ok' => false, 'detail' => 'A verified telephony adapter and media gateway are a later milestone.'],
        ];
    }
}
