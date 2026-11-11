<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Resolve through membership, not through an untrusted organization ID.
        $organization = $user->organizations()->whereKey($user->current_organization_id)->first();
        abort_unless($organization, 403, 'You do not have access to this workspace.');
        $request->attributes->set('organization', $organization);
        View::share('workspace', $organization);

        return $next($request);
    }
}
