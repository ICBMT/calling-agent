<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\VoiceSession;
use App\Services\Voice\VoiceFailure;
use App\Services\Voice\VoiceInfrastructure;
use App\Services\Voice\VoiceSelection;
use App\Services\Voice\VoiceSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PlaygroundController extends Controller
{
    public function __invoke(Request $request, VoiceInfrastructure $infrastructure, VoiceSelection $selection, VoiceSessions $sessions): View
    {
        $organization = $request->attributes->get('organization');
        $canStart = Gate::allows('manage', $organization);
        $agents = $organization->agents()->orderBy('name')->get(['id', 'name', 'language']);
        $choices = null;
        $blockers = [];
        if (! $canStart) {
            $blockers[] = 'Only workspace owners and admins can start a potentially billable AI session.';
        }
        if ($agents->isEmpty()) {
            $blockers[] = 'Save a draft agent with instructions before starting a voice session.';
        }
        if (! $infrastructure->configured()) {
            $blockers[] = VoiceFailure::messageFor('not_configured');
        }
        if ($canStart) {
            try {
                $choices = $selection->choices($organization, $request->user());
            } catch (VoiceFailure $exception) {
                $blockers[] = $exception->getMessage();
            }
        }
        $active = $canStart ? VoiceSession::query()->forOrganization($organization)->where('user_id', $request->user()->id)->whereNull('ended_at')->first() : null;
        $activeStatus = $active ? $sessions->inspect($active) : null;

        return view('workspace.playground', [
            'agents' => $agents, 'choices' => $choices, 'blockers' => $blockers,
            'voiceOptions' => [
                'available' => $blockers === [], 'choices' => $choices, 'agents' => $agents->toArray(),
                'active' => $activeStatus && ! $activeStatus['ended_at'] ? $activeStatus : null,
                'session_seconds' => (int) config('voice.session_seconds'),
                // Browser fetches must follow the real named routes, never a hardcoded prefix.
                'endpoints' => [
                    'store' => route('workspace.voice-sessions.store'),
                    'show' => route('workspace.voice-sessions.show', ['session' => '__SESSION__']),
                    'destroy' => route('workspace.voice-sessions.destroy', ['session' => '__SESSION__']),
                ],
            ],
        ]);
    }
}
