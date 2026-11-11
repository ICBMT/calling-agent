<?php

namespace App\Policies;

use App\Models\ProviderConfiguration;
use App\Models\User;

class ProviderConfigurationPolicy
{
    public function view(User $user, ProviderConfiguration $configuration): bool
    {
        return $configuration->scope === 'platform' && $configuration->organization_id === null
            ? $user->isPlatformProviderOperator()
            : $configuration->scope === 'organization' && $configuration->organization !== null
                && $user->hasOrganizationRole($configuration->organization, ['owner', 'admin', 'member']);
    }

    public function manage(User $user, ProviderConfiguration $configuration): bool
    {
        return $configuration->scope === 'platform' && $configuration->organization_id === null
            ? $user->isPlatformProviderOperator()
            : $configuration->scope === 'organization' && $configuration->organization !== null
                && $user->hasOrganizationRole($configuration->organization, ['owner', 'admin']);
    }
}
