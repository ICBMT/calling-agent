<?php

namespace App\Services\Providers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** No request bodies, raw exception messages/chains, or debug pages on credential routes. */
final class ProviderExceptionBoundary
{
    public static function matches(Request $request): bool
    {
        return $request->is('app/providers', 'app/providers/*', 'app/platform/providers', 'app/platform/providers/*');
    }

    public static function report(Throwable $exception): ?bool
    {
        if (! app()->bound('request') || ! self::matches(request())) {
            return null;
        }

        Log::error('Provider operation failed.', ['exception_class' => $exception::class]);

        // Stop Laravel from also reporting the raw message, exception object or context.
        return false;
    }

    public static function render(Throwable $exception, Request $request): ?Response
    {
        if (! self::matches($request) || $exception instanceof ValidationException || $exception instanceof AuthenticationException) {
            return null; // Retain normal safe validation/redirect behavior and dontFlash protections.
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        $message = $status >= 500
            ? 'The provider operation could not be completed. Please retry or contact the installation operator.'
            : (Response::$statusTexts[$status] ?? 'The request was rejected.');
        $response = $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : response($message, $status)->header('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                if (in_array(strtolower($name), ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'], true)
                    && preg_match('/\A\d{1,12}\z/D', (string) $value)) {
                    $response->headers->set($name, (string) $value);
                }
            }
        }

        return $response;
    }
}
