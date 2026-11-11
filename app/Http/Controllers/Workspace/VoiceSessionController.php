<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\StartVoiceSessionRequest;
use App\Services\Voice\VoiceSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceSessionController extends Controller
{
    public function store(StartVoiceSessionRequest $request, VoiceSessions $sessions): JsonResponse
    {
        $data = $request->validated();
        $data['consent'] = $request->boolean('consent');

        return response()->json($sessions->start($request->attributes->get('organization'), $request->user(), $data), 201);
    }

    public function show(Request $request, string $session, VoiceSessions $sessions): JsonResponse
    {
        return response()->json(['session' => $sessions->inspect($sessions->findForActor($request->attributes->get('organization'), $request->user(), $session))]);
    }

    public function destroy(Request $request, string $session, VoiceSessions $sessions): JsonResponse
    {
        $record = $sessions->findForActor($request->attributes->get('organization'), $request->user(), $session);

        return response()->json(['session' => $sessions->publicStatus($sessions->stop($record))]);
    }
}
