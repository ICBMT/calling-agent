<?php

use App\Http\Middleware\AuthenticateVoiceWorker;
use App\Http\Middleware\EnsureWorkspace;
use App\Http\Middleware\ProviderScope;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Providers\ProviderExceptionBoundary;
use App\Services\Voice\VoiceExceptionBoundary;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Avoid retaining request arguments (including newly entered secrets) in exception traces.
ini_set('zend.exception_ignore_args', '1');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('internal/voice')->name('voice.worker.')->middleware(['throttle:voice-worker', AuthenticateVoiceWorker::class])->group(base_path('routes/voice.php'));
            Route::prefix('api/telephony')->name('telephony.')->group(base_path('routes/telephony.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '127.0.0.1'),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['workspace' => EnsureWorkspace::class, 'provider.scope' => ProviderScope::class]);
        $middleware->trimStrings(except: ['api_key']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('workspace.overview'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report([ProviderExceptionBoundary::class, 'report']);
        $exceptions->report([VoiceExceptionBoundary::class, 'report']);
        $exceptions->render([ProviderExceptionBoundary::class, 'render']);
        $exceptions->render([VoiceExceptionBoundary::class, 'render']);
        $exceptions->dontFlash(['api_key', 'credentials', 'secret', 'token', 'ticket', 'lease', 'configuration_snapshot']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'internal/voice/*', 'app/voice-sessions', 'app/voice-sessions/*') || $request->expectsJson());
    })->create();
