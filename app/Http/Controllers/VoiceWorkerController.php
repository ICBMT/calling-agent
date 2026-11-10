<?php

namespace App\Http\Controllers;

use App\Services\Voice\VoiceFailure;
use App\Services\Voice\WorkerSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoiceWorkerController extends Controller
{
    private function data(Request $request, string $operation): array
    {
        $base = [
            'room' => ['required', 'string', 'regex:/\Avoca-[a-f0-9-]{36}\z/D'],
            'job_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/D'],
        ];
        $rules = $operation === 'claim' ? $base + ['ticket' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D']] : $base + [
            'lease' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'sequence' => ['required', 'integer', 'min:1', 'max:100000'],
            'metrics' => ['present', 'array'],
        ];
        if ($operation === 'heartbeat') {
            $rules['state'] = ['required', Rule::in(['starting', 'active'])];
        } elseif ($operation === 'finish') {
            $rules['error_code'] = ['nullable', Rule::in(['provider_auth', 'provider_quota', 'provider_timeout', 'provider_error', 'participant_timeout', 'browser_disconnected', 'worker_timeout', 'session_expired'])];
        }
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw new VoiceFailure('invalid_request', 422);
        }

        return $request->validate($rules);
    }

    public function claim(Request $request, string $session, WorkerSessions $sessions): JsonResponse
    {
        return response()->json($sessions->claim($session, $this->data($request, 'claim'))->payload());
    }

    public function heartbeat(Request $request, string $session, WorkerSessions $sessions): JsonResponse
    {
        return response()->json($sessions->heartbeat($session, $this->data($request, 'heartbeat')));
    }

    public function finish(Request $request, string $session, WorkerSessions $sessions): JsonResponse
    {
        return response()->json($sessions->finish($session, $this->data($request, 'finish')));
    }
}
