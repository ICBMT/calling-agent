<?php

namespace App\Services\Voice;

use App\Models\Organization;
use App\Models\ProviderConfiguration;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class VoiceSessions
{
    public function __construct(
        private readonly VoiceInfrastructure $infrastructure,
        private readonly VoiceSelection $selection,
        private readonly LiveKitGateway $livekit,
        private readonly LiveKitTokens $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function start(Organization $organization, User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize('manage', $organization);
        $this->infrastructure->requireConfigured();
        if (array_diff(array_keys($data), ['agent_id', 'architecture', 'model', 'voice', 'language', 'consent'])
            || ($data['architecture'] ?? null) !== 'native_realtime' || ($data['consent'] ?? null) !== true) {
            throw new VoiceFailure('invalid_request', 422);
        }
        $ticket = bin2hex(random_bytes(32));
        try {
            $session = DB::transaction(function () use ($organization, $actor, $data, $ticket): VoiceSession {
                // Serializes tenant concurrency reservations on MySQL as well as the unique actor slot.
                Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('manage', $organization);
                $agent = $organization->agents()->findOrFail($data['agent_id'] ?? null);
                $resolved = $this->selection->resolve($organization, $actor);
                $configuration = ProviderConfiguration::query()->findOrFail($resolved->configurationId);
                $this->selection->validateChoice($configuration, $data['model'] ?? '', $data['voice'] ?? '', $data['language'] ?? '');
                $active = VoiceSession::query()->forOrganization($organization)->whereNotNull('active_user_key');
                if ((clone $active)->where('user_id', $actor->id)->exists()
                    || $active->count() >= max(1, min(10, (int) config('voice.organization_concurrency')))) {
                    throw new VoiceFailure('session_limit', 429);
                }
                $id = (string) Str::uuid();
                $session = new VoiceSession;
                $session->forceFill([
                    'id' => $id, 'organization_id' => $organization->id, 'user_id' => $actor->id, 'agent_id' => $agent->id,
                    'provider_configuration_id' => $configuration->id, 'credential_version' => $configuration->credential_version,
                    'configuration_revision' => $configuration->revision, 'architecture' => 'native_realtime',
                    'state' => 'provisioning', 'active_user_key' => $organization->id.':'.$actor->id,
                    'room_name' => 'voca-'.$id, 'participant_identity' => 'voca-user-'.$id, 'worker_identity' => 'voca-agent-'.$id,
                    'configuration_snapshot' => $this->selection->snapshot($agent, $data['model'], $data['voice'], $data['language'], $configuration->provider),
                    'claim_hash' => hash('sha256', $ticket),
                    'claim_expires_at' => now()->addSeconds(min(90, max(30, (int) config('voice.startup_seconds')))),
                    'expires_at' => now()->addSeconds(min(900, max(60, (int) config('voice.session_seconds')))),
                    'cleanup_pending' => true,
                ])->save();
                $this->audit->record($organization, $actor, 'voice.session.requested');

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw new VoiceFailure('session_limit', 429);
        }

        try {
            $this->livekit->createRoom($session);
            if (! VoiceSession::query()->whereKey($session->id)->where('state', 'provisioning')->update(['state' => 'dispatched'])) {
                throw new VoiceFailure('session_ended');
            }
            $dispatch = $this->livekit->dispatch($session, $ticket);
            // A fast worker may already be starting/active. Never downgrade it or revive an ended session.
            VoiceSession::query()->whereKey($session->id)->whereNull('ended_at')->update(['dispatch_id' => $dispatch]);
            $session->refresh();
            if ($reason = $this->invalidReason($session)) {
                throw new VoiceFailure($reason);
            }
            if ($session->terminal()) {
                throw new VoiceFailure('session_ended');
            }

            return [
                'session' => $this->publicStatus($session),
                'connection' => [
                    'url' => $this->infrastructure->url(), 'token' => $this->tokens->browser($session),
                    'room' => $session->room_name, 'identity' => $session->participant_identity,
                    'agent_identity' => $session->worker_identity,
                ],
            ];
        } catch (Throwable $exception) {
            $this->stop($session, 'failed', $exception instanceof VoiceFailure ? $exception->reason : 'operation_failed');
            throw $exception instanceof VoiceFailure ? $exception : new VoiceFailure('operation_failed', 503);
        }
    }

    /** Actor-scoped at both query and permission layers. A UUID is not authorization. */
    public function findForActor(Organization $organization, User $actor, string $id): VoiceSession
    {
        Gate::forUser($actor)->authorize('manage', $organization);

        return VoiceSession::query()->forOrganization($organization)->where('user_id', $actor->id)->findOrFail($id);
    }

    public function inspect(VoiceSession $session): array
    {
        if (! $session->terminal() && ($reason = $this->invalidReason($session))) {
            $session = $this->stop($session, $this->terminalState($reason), $reason);
        }

        return $this->publicStatus($session);
    }

    /** An authority lease is deliberately fail-closed when the control plane cannot validate it. */
    public function invalidReason(VoiceSession $session): ?string
    {
        if ($session->terminal()) {
            return 'session_ended';
        }
        if ($session->expires_at->lte(now())) {
            return 'session_expired';
        }
        if (! $session->claimed_at && $session->claim_expires_at->lte(now())) {
            return 'worker_timeout';
        }
        if ($session->heartbeat_at && $session->heartbeat_at->addSeconds((int) config('voice.lease_seconds'))->lte(now())) {
            return 'worker_timeout';
        }
        if (! $this->infrastructure->configured()) {
            return 'access_revoked';
        }
        try {
            $this->selection->authorizeSession($session);
        } catch (Throwable) {
            return 'access_revoked';
        }

        return null;
    }

    public function terminalState(string $reason): string
    {
        return match ($reason) {
            'session_expired' => 'expired', 'access_revoked' => 'revoked',
            default => 'failed',
        };
    }

    /** Database-only transition, safe to call inside the worker's existing transaction. */
    public function markTerminal(VoiceSession $session, string $state = 'ended', ?string $reason = null): VoiceSession
    {
        if (! in_array($state, ['ended', 'failed', 'expired', 'revoked'], true)) {
            throw new \LogicException('Invalid terminal state.');
        }

        return DB::transaction(function () use ($session, $state, $reason): VoiceSession {
            $current = VoiceSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $current->terminal()) {
                $current->forceFill([
                    'state' => $state, 'error_code' => $reason, 'ended_at' => now(),
                    'active_user_key' => null, 'claim_hash' => null, 'lease_hash' => null,
                    // There is no reason to retain draft instructions after this test ends.
                    'configuration_snapshot' => null,
                ])->save();
            }

            return $current;
        });
    }

    public function stop(VoiceSession $session, string $state = 'ended', ?string $reason = null): VoiceSession
    {
        $current = $this->markTerminal($session, $state, $reason);
        $this->cleanup($current);

        return $current->fresh();
    }

    public function cleanup(VoiceSession $session): bool
    {
        if (! $session->terminal() || ! $session->cleanup_pending) {
            return ! $session->cleanup_pending;
        }
        try {
            $this->livekit->deleteRoom($session);
            VoiceSession::query()->whereKey($session->id)->whereNotNull('ended_at')->update([
                'cleanup_pending' => false, 'room_deleted_at' => now(),
            ]);

            return true;
        } catch (Throwable) {
            // Keep a durable retry obligation. No fabricated cleanup success/raw upstream log.
            return false;
        }
    }

    public function publicStatus(VoiceSession $session): array
    {
        return [
            'id' => $session->id, 'state' => $session->state,
            'error_code' => $session->error_code,
            'message' => $session->error_code ? VoiceFailure::messageFor($session->error_code) : null,
            'expires_at' => $session->expires_at->toIso8601String(),
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'cleanup_pending' => $session->terminal() && $session->cleanup_pending,
            'metrics' => $session->metrics ?? [],
        ];
    }

    /** Run every minute. Work is bounded; rerun until cleanup_pending drains after an outage. */
    public function sweep(int $limit = 100): array
    {
        $closed = $cleaned = 0;
        foreach (VoiceSession::query()->whereNull('ended_at')->orderBy('created_at')->limit($limit)->get() as $session) {
            if ($reason = $this->invalidReason($session)) {
                $this->markTerminal($session, $this->terminalState($reason), $reason);
                $closed++;
            }
        }
        foreach (VoiceSession::query()->whereNotNull('ended_at')->where('cleanup_pending', true)->orderBy('updated_at')->limit($limit)->get() as $session) {
            if ($this->cleanup($session)) {
                $cleaned++;
            }
        }
        DB::table('voice_worker_nonces')->where('expires_at', '<=', now())->delete();
        VoiceSession::query()->where('cleanup_pending', false)->where('ended_at', '<', now()->subDays(max(1, (int) config('voice.metadata_retention_days'))))->delete();

        return ['closed' => $closed, 'cleaned' => $cleaned];
    }
}
