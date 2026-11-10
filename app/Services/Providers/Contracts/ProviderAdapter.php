<?php

namespace App\Services\Providers\Contracts;

use App\Services\Providers\Capability;
use App\Services\Providers\Data\Discovery;
use App\Services\Providers\Data\ProviderCredentials;

interface ProviderAdapter
{
    /** Authenticated, read-only capability discovery. NOT an audio/inference test. */
    public function discover(#[\SensitiveParameter] ProviderCredentials $credentials, Capability $capability): Discovery;
}
