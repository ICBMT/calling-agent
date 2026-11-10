<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.hash('sha256', Str::lower((string) $request->input('email')).'|'.$request->ip())),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('provider-tests', fn (Request $request) => [
            Limit::perMinute(5)->by('provider-test-user:'.$request->user()->id),
            Limit::perMinute(15)->by('provider-test-scope:'.($request->routeIs('workspace.platform.providers.*') ? 'platform' : $request->user()->current_organization_id)),
        ]);
        RateLimiter::for('provider-writes', fn (Request $request) => Limit::perMinute(30)->by('provider-write:'.$request->user()->id));
        RateLimiter::for('voice-starts', fn (Request $request) => [
            Limit::perMinute(3)->by('voice-start-user:'.$request->user()->id),
            Limit::perMinute(10)->by('voice-start-org:'.$request->user()->current_organization_id),
        ]);
        RateLimiter::for('voice-polls', fn (Request $request) => Limit::perMinute(60)->by('voice-poll:'.$request->user()->id));
        RateLimiter::for('voice-stops', fn (Request $request) => Limit::perMinute(20)->by('voice-stop:'.$request->user()->id));
        RateLimiter::for('voice-worker', fn (Request $request) => Limit::perMinute(600)->by('voice-worker:'.$request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
    }
}
