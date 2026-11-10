<?php

namespace App\Services\Voice;

use App\Models\VoiceSession;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WorkerSessions
{
    public function __construct(private readonly VoiceSessions $sessions, private readonly VoiceSelection $selection) {}

    public function claim(string $id, #[\SensitiveParameter] array $data): WorkerGrant
    {
        $lease = bin2hex(random_bytes(32));
        $result = DB::transaction(function () use ($id, $data, $lease): WorkerGrant|VoiceSession {
            $session = VoiceSession::query()->lockForUpdate()->findOrFail($id);
            if ($session->terminal() || $session->state !== 'dispatched' || ! $session->claim_hash
                || ! hash_equals($session->claim_hash, hash('sha256', $data['ticket']))
                || $data['room'] !== $session->room_name) {
                throw new VoiceFailure('invalid_worker_request', 401);
            }
            if ($reason = $this->sessions->invalidReason($session)) {
                return $this->sessions->markTerminal($session, $this->sessions->terminalState($reason), $reason);
            }
            try {
                // No worker-only backdoor around M2's initiating actor and current routing/version checks.
                $resolved = $this->selection->authorizeSession($session);
                $snapshot = $session->configuration_snapshot;
                $resolved->credentials()->assertSafeMetadata($snapshot);
            } catch (Throwable) {
                return $this->sessions->markTerminal($session, 'revoked', 'access_revoked');
            }
            $session->forceFill([
                'state' => 'starting', 'claim_hash' => null, 'lease_hash' => hash('sha256', $lease),
                'worker_job_id' => $data['job_id'], 'claimed_at' => now(), 'heartbeat_at' => now(),
            ])->save();

            return new WorkerGrant([
                'v' => 1, 'session_id' => $session->id, 'room' => $session->room_name,
                'participant_identity' => $session->participant_identity, 'worker_identity' => $session->worker_identity,
                'lease' => $lease, 'expires_at' => $session->expires_at->timestamp,
                'heartbeat_seconds' => (int) config('voice.heartbeat_seconds'), 'lease_seconds' => (int) config('voice.lease_seconds'),
                'configuration' => $snapshot,
                'credentials' => [
                    'api_key' => $resolved->credentials()->key(), 'project_id' => $resolved->credentials()->projectId,
                    'organization' => $resolved->credentials()->organization,
                ],
            ]);
        });
        if ($result instanceof VoiceSession) {
            $this->sessions->cleanup($result);
            throw new VoiceFailure($result->error_code ?? 'session_ended');
        }

        return $result;
    }

    public function heartbeat(string $id, #[\SensitiveParameter] array $data): array
    {
        $metrics = VoiceMetrics::normalize($data['metrics'] ?? []);
        $session = DB::transaction(function () use ($id, $data, $metrics): VoiceSession {
            $session = $this->boundLease($id, $data);
            if ($reason = $this->sessions->invalidReason($session)) {
                return $this->sessions->markTerminal($session, $this->sessions->terminalState($reason), $reason);
            }
            if ($session->state === 'active' && $data['state'] !== 'active') {
                throw new VoiceFailure('invalid_request', 409);
            }
            $session->forceFill([
                'event_sequence' => $data['sequence'], 'heartbeat_at' => now(), 'state' => $data['state'],
                'started_at' => $session->started_at ?? ($data['state'] === 'active' ? now() : null),
                'metrics' => $metrics,
            ])->save();

            return $session;
        });
        if ($session->terminal()) {
            $this->sessions->cleanup($session);
        }

        return ['continue' => ! $session->terminal(), 'state' => $session->state, 'error_code' => $session->error_code, 'expires_at' => $session->expires_at->timestamp];
    }

    public function finish(string $id, #[\SensitiveParameter] array $data): array
    {
        $metrics = VoiceMetrics::normalize($data['metrics'] ?? []);
        $session = DB::transaction(function () use ($id, $data, $metrics): VoiceSession {
            $session = $this->boundLease($id, $data);
            $session->forceFill(['event_sequence' => $data['sequence'], 'metrics' => $metrics])->save();

            return $this->sessions->markTerminal($session, ($data['error_code'] ?? null) ? 'failed' : 'ended', $data['error_code'] ?? null);
        });
        $this->sessions->cleanup($session);

        return ['ended' => true, 'cleanup_pending' => $session->fresh()->cleanup_pending];
    }

    private function boundLease(string $id, #[\SensitiveParameter] array $data): VoiceSession
    {
        $session = VoiceSession::query()->lockForUpdate()->findOrFail($id);
        if ($session->terminal() || ! $session->lease_hash || $session->worker_job_id !== $data['job_id']
            || $session->room_name !== $data['room'] || ! hash_equals($session->lease_hash, hash('sha256', $data['lease']))) {
            throw new VoiceFailure('invalid_worker_request', 401);
        }
        if (! is_int($data['sequence']) || $data['sequence'] <= $session->event_sequence || $data['sequence'] > 100000) {
            throw new VoiceFailure('invalid_request', 409);
        }

        return $session;
    }
}
