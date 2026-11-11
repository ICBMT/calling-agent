<?php

namespace App\Models;

use App\Services\Providers\Capability;
use App\Services\Providers\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderConfiguration extends Model
{
    // Secret, scope, routing and verification fields must only be set by trusted services.
    protected $fillable = ['name', 'enabled', 'project_id', 'provider_organization'];

    protected $hidden = ['api_key', 'test_token'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted', 'enabled' => 'boolean',
            'catalog' => 'array', 'usage_snapshot' => 'array', 'warnings' => 'array',
            'last_tested_at' => 'datetime', 'last_success_at' => 'datetime',
            'credential_version' => 'integer', 'tested_version' => 'integer', 'revision' => 'integer',
        ];
    }

    public function scopeForScope(Builder $query, ?Organization $organization): Builder
    {
        return $organization
            ? $query->where('scope', 'organization')->where('organization_id', $organization->id)
            : $query->where('scope', 'platform')->whereNull('organization_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function connectionTests(): HasMany
    {
        return $this->hasMany(ProviderConnectionTest::class);
    }

    public function hasCredential(): bool
    {
        return filled($this->getRawOriginal('api_key'));
    }

    public function freshCatalog(): bool
    {
        return $this->connection_status === 'authenticated'
            && $this->tested_version === $this->credential_version
            && $this->last_tested_at !== null
            && $this->last_tested_at->between(now()->subHours(config('providers.catalog_ttl_hours')), now()->addSecond());
    }

    public function readyForResolution(): bool
    {
        $capability = Capability::tryFrom($this->capability);
        $catalog = $this->catalog;
        $definitions = config('providers.definitions');

        return $this->enabled && $this->hasCredential() && $this->freshCatalog() && $capability
            && in_array($capability->value, $definitions[$this->provider]['capabilities'] ?? [], true)
            && is_array($catalog) && Catalog::validSelection($catalog, $capability, $this->selected_model, $this->selected_voice, $this->provider);
    }

    public function statusLabel(): string
    {
        return match (true) {
            ! $this->hasCredential() => 'No credential',
            $this->connection_status === 'failed' => 'Check failed',
            $this->freshCatalog() => 'Authentication verified',
            $this->connection_status === 'authenticated' => 'Recheck required',
            default => 'Not tested',
        };
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
