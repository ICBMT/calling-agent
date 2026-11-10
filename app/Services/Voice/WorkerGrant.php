<?php

namespace App\Services\Voice;

/** Only the HMAC-authenticated internal claim controller may deliberately extract this payload. */
final readonly class WorkerGrant implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private array $data) {}

    public function payload(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return ['credentials' => '[redacted]'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Worker grants must not be serialized into jobs, caches or browser sessions.');
    }
}
