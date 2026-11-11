<?php

namespace App\Services\Providers;

use App\Services\Providers\Data\ProviderCredentials;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

final class ProviderHttpClient
{
    private const MAX_BYTES = 2097152;

    private const ORIGINS = [
        'openai' => 'https://api.openai.com',
        'gemini' => 'https://generativelanguage.googleapis.com',
        'deepgram' => 'https://api.deepgram.com',
        'elevenlabs' => 'https://api.elevenlabs.io',
    ];

    public function get(string $provider, string $path, #[\SensitiveParameter] ProviderCredentials $credentials, #[\SensitiveParameter] array $query = [], ?float $deadline = null): array
    {
        $allowed = match ($provider) {
            'openai' => $path === '/v1/models',
            'gemini' => in_array($path, ['/v1beta/models', '/v1beta/voices'], true),
            'deepgram' => in_array($path, ['/v1/auth/token', '/v1/models'], true),
            'elevenlabs' => in_array($path, ['/v1/user/subscription', '/v1/models', '/v2/voices'], true),
            default => false,
        };
        if (! $allowed || ! isset(self::ORIGINS[$provider])) {
            throw new ProviderFailure('unexpected_response');
        }
        // Pagination tokens are untrusted metadata too. Never put the API key into any URL.
        $allowedQuery = match ($provider) {
            'gemini' => $path === '/v1beta/voices' ? ['page_size', 'page_token', 'type'] : ['pageSize', 'pageToken'],
            'deepgram' => str_ends_with($path, '/models') ? ['include_outdated'] : [],
            'elevenlabs' => $path === '/v2/voices' ? ['page_size', 'next_page_token', 'include_total_count'] : [],
            default => [],
        };
        if (array_diff(array_keys($query), $allowedQuery) || $path !== $credentials->redact($path)) {
            throw new ProviderFailure('unexpected_response');
        }
        foreach ($query as $value) {
            if ((! is_string($value) && ! is_int($value) && ! is_bool($value)) || (is_string($value) && $value !== $credentials->redact($value))) {
                throw new ProviderFailure('unexpected_response');
            }
        }
        $remaining = ($deadline ?? (microtime(true) + 8)) - microtime(true);
        if ($remaining <= 0) {
            throw new ProviderFailure('network_error');
        }
        $headers = match ($provider) {
            'gemini' => ['x-goog-api-key' => $credentials->key()],
            'elevenlabs' => ['xi-api-key' => $credentials->key()],
            'deepgram' => ['Authorization' => 'Token '.$credentials->key()],
            default => array_filter([
                'Authorization' => 'Bearer '.$credentials->key(),
                'OpenAI-Project' => $credentials->projectId,
                'OpenAI-Organization' => $credentials->organization,
            ], fn ($value) => $value !== null && $value !== ''),
        };

        try {
            $response = Http::acceptJson()->withHeaders($headers)
                ->connectTimeout(min(3, $remaining))->timeout(min(8, $remaining))
                ->withoutRedirecting()->withOptions([
                    'verify' => true,
                    'cookies' => false,
                    'on_headers' => function ($response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new ProviderFailure('response_too_large', $response->getStatusCode());
                        }
                    },
                    'progress' => function ($total, $downloaded): void {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new ProviderFailure('response_too_large');
                        }
                    },
                ])->get(self::ORIGINS[$provider].$path, $query);
        } catch (ProviderFailure $exception) {
            throw $exception;
        } catch (ConnectionException|GuzzleException $exception) {
            // Laravel and Guzzle can wrap our size guard. Preserve only our own safe category/status,
            // never the upstream request, exception body, headers, or exception chain.
            $cause = $exception->getPrevious();
            for ($depth = 0; $cause && $depth < 8; $depth++, $cause = $cause->getPrevious()) {
                if ($cause instanceof ProviderFailure) {
                    throw new ProviderFailure($cause->reason, $cause->httpStatus);
                }
            }
            throw new ProviderFailure('network_error');
        }

        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new ProviderFailure('response_too_large', $response->status());
        }
        if ($response->status() !== 200) {
            $data = $response->json();
            $code = is_array($data) ? data_get($data, 'error.code', data_get($data, 'detail.status', data_get($data, 'err_code', ''))) : '';
            $details = is_array($data) ? data_get($data, 'error.details', []) : [];
            $reasons = is_array($details) ? array_values(array_filter(array_column(array_filter($details, 'is_array'), 'reason'), 'is_string')) : [];
            $reason = match (true) {
                in_array('API_KEY_INVALID', $reasons, true) => 'invalid_credentials',
                count(array_intersect($reasons, ['API_KEY_SERVICE_BLOCKED', 'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED'])) > 0,
                in_array($code, ['missing_permissions', 'INSUFFICIENT_PERMISSIONS'], true) => 'permissions',
                in_array($code, ['insufficient_quota', 'quota_exceeded', 'INSUFFICIENT_CREDITS'], true) => 'quota',
                in_array($code, ['API_KEY_INVALID', 'invalid_api_key', 'INVALID_AUTH'], true), $response->status() === 401 => 'invalid_credentials',
                $response->status() === 403 => 'permissions',
                $response->status() === 429 => 'rate_limited',
                $response->status() === 404 => 'not_found',
                $response->status() >= 500 => 'provider_unavailable',
                $response->status() >= 300 && $response->status() < 400 => 'redirect_blocked',
                default => 'unexpected_response',
            };
            throw new ProviderFailure($reason, $response->status());
        }
        try {
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderFailure('unexpected_response', $response->status());
        }
        if (! is_array($data) || isset($data['error']) || isset($data['err_code'])) {
            throw new ProviderFailure('unexpected_response', $response->status());
        }

        return $data;
    }

    /** Bound list shape, not just count; a JSON object is not a successful list. */
    public static function list(array $data, string $key, int $maximum = 1000): array
    {
        $list = $data[$key] ?? null;
        if (! is_array($list) || ! array_is_list($list) || count($list) > $maximum || count(array_filter($list, 'is_array')) !== count($list)) {
            throw new ProviderFailure('unexpected_response', 200);
        }

        return $list;
    }
}
