<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWorkspaceRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('workspace.settings', ['timezones' => \DateTimeZone::listIdentifiers()]);
    }

    public function update(UpdateWorkspaceRequest $request, AuditLogger $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        DB::transaction(function () use ($organization, $request, $audit): void {
            $organization->update($request->validated());
            $audit->record($organization, $request->user(), 'workspace.updated', $organization);
        });

        return back()->with('status', 'Workspace settings saved.');
    }
}
