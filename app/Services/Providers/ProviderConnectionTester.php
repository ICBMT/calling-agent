<?php

namespace App\Services\Providers;

use App\Models\ProviderConfiguration;
use App\Models\ProviderConnectionTest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Providers\Data\ProviderCredentials;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProviderConnectionTester
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ProviderConfigurationStore $store,
        private readonly AuditLogger $audit,
    ) {}

    public function test(ProviderConfiguration $configuration, User $user, int $revision): ?ProviderConnectionTest
    {
        Gate::forUser($user)->authorize('manage', $configuration);
        $lock = Cache::lock('provider-test:'.$configuration->id, 45);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'A connection check is already running for this configuration. Wait before testing again.']);
        }
        try {
            return $this->run($configuration, $user, $revision);
        } finally {
            $lock->release();
        }
    }

    private function run(ProviderConfiguration $configuration, User $user, int $revision): ?ProviderConnectionTest
    {
        $organization = $configuration->organization;
        $snapshot = $this->store->transaction($organization, function () use ($organization, $configuration, $user, $revision) {
            $record = ProviderConfiguration::query()->forScope($organization)->findOrFail($configuration->id);
            Gate::forUser($user)->authorize('manage', $record);
            $this->store->assertRevision($record, $revision);
            if (! $record->hasCredential()) {
                throw ValidationException::withMessages(['connection' => 'Save an API key before testing this connection.']);
            }
            $record->test_token = (string) Str::uuid();
            $record->save();

            return $record;
        });
        $start = microtime(true);
        $discovery = null;
        $reason = 'authenticated';
        $httpStatus = 200;
        try {
            $credentials = new ProviderCredentials($snapshot->api_key, $snapshot->project_id, $snapshot->provider_organization);
            $discovery = $this->registry->adapter($snapshot->provider, Capability::from($snapshot->capability))->discover($credentials, Capability::from($snapshot->capability));
            $credentials->assertSafeMetadata([$discovery->models, $discovery->voices, $discovery->usage, $discovery->warnings]);
        } catch (DecryptException) {
            $reason = 'credential_unreadable';
            $httpStatus = null;
        } catch (ProviderFailure $failure) {
            $reason = $failure->reason;
            $httpStatus = $failure->httpStatus;
        } catch (\Throwable) {
            // Fail closed. No raw HTTP/SDK exception or request can reach logs, session or browser.
            $reason = 'unexpected_response';
            $httpStatus = null;
        }
        if ($reason !== 'authenticated') {
            $discovery = null;
        }
        $selectionUnavailable = $discovery && $snapshot->selected_model !== null
            && ! Catalog::validSelection(['models' => $discovery->models, 'voices' => $discovery->voices], Capability::from($snapshot->capability), $snapshot->selected_model, $snapshot->selected_voice, $snapshot->provider);
        if ($selectionUnavailable) {
            $reason = 'selection_unavailable';
        }
        $duration = (int) round((microtime(true) - $start) * 1000);
        $finishedAt = now();
        $message = $discovery
            ? 'Authentication and read-only catalog requests passed. '.count($discovery->models).' supported model(s) found. Audio, inference permissions, and telephone calls were not tested.'.($selectionUnavailable ? ' The saved model/voice is unavailable in the refreshed catalog; routing is blocked.' : '')
            : ProviderFailure::description($reason);

        return $this->store->transaction($organization, function () use ($organization, $snapshot, $user, $discovery, $reason, $httpStatus, $duration, $finishedAt, $message, $selectionUnavailable): ?ProviderConnectionTest {
            $record = ProviderConfiguration::query()->forScope($organization)->find($snapshot->id);
            if (! $record) {
                return null; // A deleted configuration must never be recreated by a late result.
            }
            $currentActor = User::query()->find($user->id);
            $authorized = $currentActor && Gate::forUser($currentActor)->allows('manage', $record);
            $applied = $authorized && $record->revision === $snapshot->revision
                && $record->credential_version === $snapshot->credential_version
                && $record->test_token === $snapshot->test_token;
            $test = $record->connectionTests()->create([
                'user_id' => $currentActor?->id, 'credential_version' => $snapshot->credential_version,
                'outcome' => $discovery ? 'authenticated' : 'failed', 'code' => $reason, 'message' => $message,
                'http_status' => $httpStatus, 'duration_ms' => max(0, $duration),
                'model_count' => $discovery ? count($discovery->models) : 0, 'applied' => $applied, 'created_at' => $finishedAt,
            ]);
            if ($applied) {
                $record->forceFill([
                    'connection_status' => $discovery ? 'authenticated' : 'failed',
                    'last_tested_at' => $finishedAt, 'tested_version' => $snapshot->credential_version,
                    'last_success_at' => $discovery ? $finishedAt : $record->last_success_at,
                    'last_error_code' => $discovery ? null : $reason, 'last_message' => $message,
                    'last_http_status' => $httpStatus, 'test_token' => null,
                    'catalog' => $discovery ? ['models' => $discovery->models, 'voices' => $discovery->voices] : null,
                    'usage_snapshot' => $discovery && count($discovery->usage) ? ['fetched_at' => $finishedAt->toIso8601String(), 'items' => $discovery->usage] : null,
                    'warnings' => $discovery ? [...$discovery->warnings, ...($selectionUnavailable ? ['The previously selected model/voice is no longer available. Choose a compatible selection before this configuration can resolve. No substitution was made.'] : [])] : null, 'revision' => $record->revision + 1,
                ])->save();
            }
            $keepIds = $record->connectionTests()->latest('id')->limit(20)->pluck('id');
            $record->connectionTests()->whereNotIn('id', $keepIds)->delete();
            if ($currentActor) {
                $this->audit->record($organization, $currentActor, $applied ? 'provider.test_'.$test->outcome : 'provider.test_discarded', $record);
            }

            return $test;
        });
    }
}
