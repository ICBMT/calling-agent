<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgentRequest;
use App\Models\Agent;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = $request->attributes->get('organization')->agents()->latest();
        if ($search = $data['q'] ?? null) {
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']);
        }

        return view('workspace.agents.index', ['agents' => $query->paginate(12)->withQueryString(), 'search' => $search ?? '']);
    }

    public function create(Request $request): View
    {
        Gate::authorize('manage', $request->attributes->get('organization'));

        return view('workspace.agents.form', ['agent' => new Agent(['language' => $request->attributes->get('organization')->default_language])]);
    }

    public function store(StoreAgentRequest $request, AuditLogger $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $agent = DB::transaction(function () use ($organization, $request, $audit): Agent {
            $agent = $organization->agents()->create($request->validated());
            $audit->record($organization, $request->user(), 'agent.created', $agent);

            return $agent;
        });

        return redirect()->route('workspace.agents.edit', $agent)->with('status', 'Draft saved. Your agent is taking shape.');
    }

    public function edit(Request $request, string $agent): View
    {
        $record = $request->attributes->get('organization')->agents()->findOrFail($agent);

        return view('workspace.agents.form', ['agent' => $record]);
    }

    public function update(StoreAgentRequest $request, string $agent, AuditLogger $audit): RedirectResponse
    {
        $record = $request->attributes->get('organization')->agents()->findOrFail($agent);
        Gate::authorize('update', $record);
        DB::transaction(function () use ($record, $request, $audit): void {
            $record->update($request->validated());
            $audit->record($record->organization, $request->user(), 'agent.updated', $record);
        });

        return back()->with('status', 'Changes saved. Looking good.');
    }

    public function destroy(Request $request, string $agent, AuditLogger $audit): RedirectResponse
    {
        $record = $request->attributes->get('organization')->agents()->findOrFail($agent);
        Gate::authorize('delete', $record);
        DB::transaction(function () use ($record, $request, $audit): void {
            $record->delete();
            $audit->record($record->organization, $request->user(), 'agent.deleted', $record);
        });

        return redirect()->route('workspace.agents.index')->with('status', 'Agent removed. Existing call records are retained.');
    }
}
