<?php

namespace App\Services\Providers\Data;

use App\Services\Providers\ProviderFailure;

final class ProviderCredentials implements \JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $key,
        public readonly ?string $projectId = null,
        public readonly ?string $organization = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function redact(#[\SensitiveParameter] string $value): string
    {
        return $this->key === '' ? $value : str_replace($this->key, '[redacted]', $value);
    }

    /** Final guard for every normalized field, including numeric allowance and language metadata. */
    public function assertSafeMetadata(#[\SensitiveParameter] array $metadata): void
    {
        foreach ($metadata as $name => $value) {
            if ((string) $name !== $this->redact((string) $name)) {
                throw new ProviderFailure('unexpected_response', 200);
            }
            if (is_float($value) && ! is_finite($value)) {
                throw new ProviderFailure('unexpected_response', 200);
            }
            if (is_array($value)) {
                $this->assertSafeMetadata($value);
            } elseif ((is_string($value) || is_int($value) || is_float($value)) && (string) $value !== $this->redact((string) $value)) {
                throw new ProviderFailure('unexpected_response', 200);
            } elseif (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
                throw new ProviderFailure('unexpected_response', 200);
            }
        }
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Provider credentials must not be serialized into jobs, sessions, or caches.');
    }

    public function __debugInfo(): array
    {
        return ['key' => '[redacted]'];
    }
}
