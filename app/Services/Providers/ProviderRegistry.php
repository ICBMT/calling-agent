<?php

namespace App\Services\Providers;

use App\Services\Providers\Contracts\ProviderAdapter;
use InvalidArgumentException;

final class ProviderRegistry
{
    public function definitions(): array
    {
        return config('providers.definitions');
    }

    public function definition(string $provider): array
    {
        return $this->definitions()[$provider] ?? throw new InvalidArgumentException('Unsupported provider.');
    }

    public function supports(string $provider, Capability $capability): bool
    {
        return in_array($capability->value, $this->definitions()[$provider]['capabilities'] ?? [], true);
    }

    public function adapter(string $provider, Capability $capability): ProviderAdapter
    {
        if (! $this->supports($provider, $capability)) {
            throw new InvalidArgumentException('Provider does not support this capability.');
        }

        return app($this->definition($provider)['adapter']);
    }
}
