<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Services\SystemHealth;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health): View
    {
        $data = $request->validate(['days' => ['sometimes', Rule::in([7, 30, 90])]]);
        $days = (int) ($data['days'] ?? 30);
        $organization = $request->attributes->get('organization');
        $calls = $organization->calls()->where('created_at', '>=', now()->subDays($days));
        $metrics = [
            'calls' => (clone $calls)->count(),
            'completed' => (clone $calls)->where('status', 'completed')->count(),
            'active' => (clone $calls)->where('status', 'in_progress')->count(),
            'average_duration' => (clone $calls)->where('status', 'completed')->avg('duration_seconds'),
            'agents' => $organization->agents()->count(),
        ];

        return view('workspace.overview', [
            'metrics' => $metrics,
            'days' => $days,
            'agents' => $organization->agents()->latest()->limit(3)->get(),
            'recentCalls' => (clone $calls)->with(['agent' => fn ($query) => $query->where('organization_id', $organization->id)])->latest()->limit(5)->get(),
            'checks' => $health->checks($request->attributes->get('organization')),
        ]);
    }
}
