<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function update(User $user, Agent $agent): bool
    {
        return $user->hasOrganizationRole($agent->organization, ['owner', 'admin']);
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $this->update($user, $agent);
    }
}
