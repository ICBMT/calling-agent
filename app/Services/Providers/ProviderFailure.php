<?php

namespace App\Services\Providers;

use RuntimeException;

final class ProviderFailure extends RuntimeException
{
    private const REASONS = [
        'invalid_credentials', 'permissions', 'quota', 'rate_limited', 'provider_unavailable',
        'not_found', 'redirect_blocked', 'network_error', 'response_too_large', 'catalog_limit',
        'credential_unreadable', 'unexpected_response',
    ];

    public readonly string $reason;

    public readonly ?int $httpStatus;

    public function __construct(#[\SensitiveParameter] string $reason, ?int $httpStatus = null)
    {
        // Even an adapter bug passing an upstream code/message must not persist a reflected key.
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : 'unexpected_response';
        $this->httpStatus = $httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : null;
        parent::__construct(self::description($this->reason));
    }

    public static function description(string $reason): string
    {
        return match ($reason) {
            'invalid_credentials' => 'The provider rejected this API key. Replace it with an active key for the correct account.',
            'permissions' => 'The provider denied this request. Check key permissions, project access, API restrictions, and account eligibility.',
            'quota' => 'The provider reported insufficient quota or credits. Check billing and account limits in its console.',
            'rate_limited' => 'The provider rate-limited this request. Wait before testing again and check account limits.',
            'provider_unavailable' => 'The provider is temporarily unavailable. Try again later and check its service status.',
            'not_found' => 'The provider could not find this project or API resource. Check the project identifier and provider documentation.',
            'redirect_blocked' => 'The provider returned a redirect. It was not followed, to protect your credentials.',
            'network_error' => 'The server could not reach the provider within the network/time limit. Check outbound HTTPS, DNS, TLS, or proxy settings; this does not prove the key is invalid.',
            'response_too_large' => 'The provider response exceeded the safe size limit. No catalog was accepted.',
            'catalog_limit' => 'The catalog exceeded the bounded pagination limit. No incomplete catalog was accepted.',
            'credential_unreadable' => 'The stored key could not be decrypted. Restore the original application encryption key or replace this credential.',
            default => 'The provider returned an unexpected response. Check the HTTP status and current provider documentation; no success was recorded.',
        };
    }
}
