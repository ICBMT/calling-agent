<?php

namespace App\Services\Voice;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Credential-bearing worker replies and room grants must never produce a debug page/raw log. */
final class VoiceExceptionBoundary
{
    public static function matches(Request $request): bool
    {
        return $request->is('internal/voice/*', 'app/voice-sessions', 'app/voice-sessions/*', 'app/playground');
    }

    public static function report(Throwable $exception): ?bool
    {
        if (! app()->bound('request') || ! self::matches(request())) {
            return null;
        }
        Log::error('Voice operation failed.', ['exception_class' => $exception::class]);

        return false;
    }

    public static function render(Throwable $exception, Request $request): ?Response
    {
        if (! self::matches($request) || ($exception instanceof AuthenticationException && ! $request->expectsJson() && ! $request->is('internal/*'))) {
            return null;
        }
        $status = match (true) {
            $exception instanceof VoiceFailure => $exception->status,
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $code = $exception instanceof VoiceFailure ? $exception->reason : ($status === 422 ? 'invalid_request' : 'request_rejected');
        $message = $exception instanceof VoiceFailure || $status === 422
            ? VoiceFailure::messageFor($code)
            : ($status >= 500 ? VoiceFailure::messageFor(null) : (Response::$statusTexts[$status] ?? 'The request was rejected.'));
        $response = response()->json(['code' => $code, 'message' => $message], $status)
            ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
        if ($exception instanceof HttpExceptionInterface && preg_match('/\A\d{1,12}\z/D', (string) ($exception->getHeaders()['Retry-After'] ?? ''))) {
            $response->header('Retry-After', $exception->getHeaders()['Retry-After']);
        }

        return $response;
    }
}
