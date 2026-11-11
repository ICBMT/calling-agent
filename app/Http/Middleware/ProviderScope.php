<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ProviderScope
{
    public function handle(Request $request, Closure $next): Response
    {
        // Scope comes from the matched, named route, NEVER a form/query parameter.
        $platform = $request->routeIs('workspace.platform.providers.*');
        abort_if($platform && ! $request->user()->isPlatformProviderOperator(), 403);
        if (! $platform) {
            Gate::authorize('view', $request->attributes->get('organization'));
        }
        $request->attributes->set('provider_scope_organization', $platform ? null : $request->attributes->get('organization'));
        $request->attributes->set('provider_platform_scope', $platform);

        return $next($request);
    }
}
