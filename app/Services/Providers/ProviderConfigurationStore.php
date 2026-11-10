<?php

namespace App\Services\Providers;

use App\Models\Organization;
use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProviderConfigurationStore
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Shared database lock prevents competing default/fallback assignments (including platform scope). */
    public function transaction(?Organization $organization, callable $callback): mixed
    {
        return DB::transaction(function () use ($organization, $callback) {
            $key = $organization ? 'organization:'.$organization->id : 'platform';
            DB::table('provider_scopes')->insertOrIgnore(['key' => $key, 'organization_id' => $organization?->id]);
            DB::table('provider_scopes')->where('key', $key)->lockForUpdate()->first();

            return $callback();
        }, 3);
    }

    public function create(?Organization $organization, User $user, #[\SensitiveParameter] array $data): ProviderConfiguration
    {
        return $this->transaction($organization, function () use ($organization, $user, $data) {
            if ($organization) {
                Gate::forUser($user)->authorize('manage', $organization);
            } else {
                abort_unless($user->isPlatformProviderOperator(), 403);
            }
            $configuration = new ProviderConfiguration;
            $configuration->forceFill([
                'organization_id' => $organization?->id, 'scope' => $organization ? 'organization' : 'platform',
                'provider' => $data['provider'], 'capability' => $data['capability'],
                'name' => $data['name'], 'enabled' => (bool) $data['enabled'],
                'api_key' => $data['api_key'], 'project_id' => $data['project_id'] ?? null,
                'provider_organization' => $data['provider_organization'] ?? null,
            ])->save();
            $this->audit->record($organization, $user, 'provider.created', $configuration);

            return $configuration;
        });
    }

    public function update(ProviderConfiguration $configuration, User $user, #[\SensitiveParameter] array $data): void
    {
        $this->mutate($configuration, $user, $data['revision'], function ($record) use ($data): void {
            $changed = false;
            foreach (['project_id', 'provider_organization'] as $field) {
                $value = $data[$field] ?? null;
                $changed = $changed || $value !== $record->$field;
                $record->$field = $value;
            }
            if (filled($data['api_key'] ?? null)) {
                $record->api_key = $data['api_key'];
                $changed = true; // Even replacing with the same key must require a new check.
            }
            $record->fill(['name' => $data['name'], 'enabled' => (bool) $data['enabled']]);
            if ($changed) {
                $this->invalidate($record);
            }
            if (! $record->enabled) {
                $record->forceFill(['routing_role' => 'none', 'routing_key' => null]);
            }
        }, 'provider.updated');
    }

    public function removeCredential(ProviderConfiguration $configuration, User $user, int $revision): void
    {
        $this->mutate($configuration, $user, $revision, function ($record): void {
            $record->api_key = null;
            $record->enabled = false;
            $this->invalidate($record);
        }, 'provider.credential_removed');
    }

    public function selection(ProviderConfiguration $configuration, User $user, #[\SensitiveParameter] array $data): void
    {
        $this->mutate($configuration, $user, $data['revision'], function ($record) use ($data): void {
            $capability = Capability::from($record->capability);
            $voice = $data['selected_voice'] ?? null;
            if (! $record->hasCredential() || ! $record->freshCatalog()) {
                throw ValidationException::withMessages(['selected_model' => 'Run a successful connection check for the current credential first. Catalogs expire after 24 hours.']);
            }
            if (! Catalog::validSelection(is_array($record->catalog) ? $record->catalog : [], $capability, $data['selected_model'], $voice, $record->provider)) {
                throw ValidationException::withMessages(['selected_model' => 'Choose a discovered model and a voice compatible with this capability. No substitution was made.']);
            }
            $record->forceFill(['selected_model' => $data['selected_model'], 'selected_voice' => $voice]);
            $role = $data['routing_role'];
            if ($role !== 'none' && ! $record->enabled) {
                throw ValidationException::withMessages(['routing_role' => 'Enable this configuration before assigning a default or fallback.']);
            }
            $key = $role === 'none' ? null : ($record->scope === 'platform' ? 'platform' : 'organization:'.$record->organization_id).':'.$record->capability.':'.$role;
            if ($key) {
                // Unique DB key is the final concurrency guard, not just a UI checkbox.
                ProviderConfiguration::query()->where('routing_key', $key)->whereKeyNot($record->id)
                    ->update(['routing_key' => null, 'routing_role' => 'none', 'revision' => DB::raw('revision + 1')]);
            }
            $record->forceFill(['routing_key' => $key, 'routing_role' => $role]);
        }, 'provider.selection_updated');
    }

    public function delete(ProviderConfiguration $configuration, User $user, int $revision): void
    {
        $this->mutate($configuration, $user, $revision, fn ($record) => $record->delete(), 'provider.deleted');
    }

    private function mutate(ProviderConfiguration $configuration, User $user, int $revision, callable $callback, string $action): void
    {
        $organization = $configuration->organization;
        $this->transaction($organization, function () use ($organization, $configuration, $user, $revision, $callback, $action): void {
            $record = ProviderConfiguration::query()->forScope($organization)->findOrFail($configuration->id);
            Gate::forUser($user)->authorize('manage', $record);
            $this->assertRevision($record, $revision);
            $callback($record);
            if ($record->exists) {
                $record->revision++;
                $record->save();
            }
            $this->audit->record($organization, $user, $action, $record);
        });
    }

    public function assertRevision(ProviderConfiguration $record, int $revision): void
    {
        if ($record->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'This configuration changed in another request. Reload before trying again. Your API key has not been saved.']);
        }
    }

    private function invalidate(ProviderConfiguration $record): void
    {
        $record->forceFill([
            'credential_version' => $record->credential_version + 1, 'test_token' => null,
            'connection_status' => 'not_tested', 'tested_version' => null,
            'last_tested_at' => null, 'last_success_at' => null, 'last_error_code' => null,
            'last_message' => null, 'last_http_status' => null,
            'catalog' => null, 'usage_snapshot' => null, 'warnings' => null,
            'selected_model' => null, 'selected_voice' => null, 'routing_key' => null, 'routing_role' => 'none',
        ]);
    }
}
