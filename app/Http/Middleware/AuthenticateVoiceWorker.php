<?php

namespace App\Http\Middleware;

use App\Services\Voice\VoiceFailure;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateVoiceWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('voice.worker_secret');
        $keyId = $request->header('X-Voca-Key-Id', '');
        $timestamp = $request->header('X-Voca-Timestamp', '');
        $nonce = $request->header('X-Voca-Nonce', '');
        $signature = $request->header('X-Voca-Signature', '');
        $body = $request->getContent();
        if (! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 256
            || ! hash_equals((string) config('voice.worker_key_id'), $keyId)
            || ! preg_match('/\A\d{10}\z/D', $timestamp) || abs(now()->timestamp - (int) $timestamp) > 60
            || ! preg_match('/\A[a-f0-9]{32}\z/D', $nonce) || ! preg_match('/\A[a-f0-9]{64}\z/D', $signature)
            || strlen($body) > 8192 || $request->getQueryString() !== null || ! $request->isJson()) {
            throw new VoiceFailure('invalid_worker_request', 401);
        }
        $canonical = implode("\n", ['v1', $keyId, $request->method(), $request->getPathInfo(), $timestamp, $nonce, hash('sha256', $body)]);
        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            throw new VoiceFailure('invalid_worker_request', 401);
        }
        try {
            // DB uniqueness is shared across PHP processes/servers; array-cache locks are not replay protection.
            DB::table('voice_worker_nonces')->insert(['hash' => hash('sha256', $keyId.':'.$nonce), 'expires_at' => now()->addMinutes(2)]);
        } catch (UniqueConstraintViolationException) {
            throw new VoiceFailure('invalid_worker_request', 401);
        }

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
