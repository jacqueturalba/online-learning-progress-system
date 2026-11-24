<?php

namespace App\Support;

use App\Models\User;

class AiServiceAssignmentPolicy
{
    public function assign(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin'], true);
    }
}
