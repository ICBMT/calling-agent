<?php

namespace App\Services\Providers\Data;

use App\Services\Providers\Capability;
use App\Services\Providers\Contracts\ProviderAdapter;

/** Server-side resolution only; never returned by a controller or serialized to a client. */
final readonly class ResolvedProvider
{
    public function __construct(
        public int $configurationId,
        public string $provider,
        public Capability $capability,
        public string $model,
        public ?string $voice,
        #[\SensitiveParameter] private ProviderCredentials $credentials,
        public ProviderAdapter $adapter,
    ) {}

    public function credentials(): ProviderCredentials
    {
        return $this->credentials;
    }

    public function __debugInfo(): array
    {
        return ['configuration_id' => $this->configurationId, 'provider' => $this->provider, 'credentials' => '[redacted]'];
    }
}
