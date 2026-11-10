<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Browser-only control-plane metadata. No model may be serialized into a public session response. */
class VoiceSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = [
        'configuration_snapshot', 'claim_hash', 'lease_hash', 'active_user_key',
        'dispatch_id', 'worker_job_id', 'room_name', 'participant_identity', 'worker_identity',
    ];

    protected function casts(): array
    {
        return [
            'configuration_snapshot' => 'encrypted:array', 'metrics' => 'array',
            'credential_version' => 'integer', 'configuration_revision' => 'integer',
            'event_sequence' => 'integer', 'cleanup_pending' => 'boolean',
            'claim_expires_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
            'room_deleted_at' => 'immutable_datetime',
        ];
    }

    public function scopeForOrganization(Builder $query, Organization $organization): Builder
    {
        return $query->where('organization_id', $organization->id);
    }

    public function terminal(): bool
    {
        return in_array($this->state, ['ended', 'failed', 'expired', 'revoked'], true);
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'state' => $this->state, 'configuration' => '[redacted]'];
    }
}
