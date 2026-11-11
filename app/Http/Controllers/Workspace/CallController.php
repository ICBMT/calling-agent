<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CallController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['incoming', 'outgoing', 'browser'])],
            'status' => ['nullable', Rule::in(['queued', 'ringing', 'in_progress', 'completed', 'failed', 'missed', 'canceled'])],
        ]);
        $organization = $request->attributes->get('organization');
        $query = $organization->calls()->with(['agent' => fn ($query) => $query->where('organization_id', $organization->id)])->latest();
        foreach (array_filter($filters) as $key => $value) {
            $query->where($key, $value);
        }

        return view('workspace.calls', ['calls' => $query->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function show(Request $request, string $publicId): View
    {
        $organization = $request->attributes->get('organization');
        $call = $organization->calls()
            ->with(['agent' => fn ($query) => $query->where('organization_id', $organization->id)])
            ->where('public_id', $publicId)
            ->firstOrFail();

        return view('workspace.call-details', ['call' => $call]);
    }
}
