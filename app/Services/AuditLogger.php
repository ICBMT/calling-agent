<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function record(?Organization $organization, User $user, string $action, ?Model $subject = null): void
    {
        // Deliberately do not store request bodies, instructions, passwords, or credentials.
        $log = new AuditLog([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
        $log->organization_id = $organization?->id;
        $log->save();
    }
}
