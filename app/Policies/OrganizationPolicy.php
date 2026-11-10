<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationRole($organization, ['owner', 'admin', 'member']);
    }

    public function manage(User $user, Organization $organization): bool
    {
        return $user->hasOrganizationRole($organization, ['owner', 'admin']);
    }
}
