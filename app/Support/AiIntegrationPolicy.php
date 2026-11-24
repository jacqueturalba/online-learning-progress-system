<?php

namespace App\Support;

use App\Models\AiIntegration;
use App\Models\User;

class AiIntegrationPolicy
{
    public function viewAny(User $user): bool { return in_array($user->role, ['admin', 'super_admin'], true); }
    public function create(User $user): bool { return $this->viewAny($user); }
    public function update(User $user, AiIntegration $integration): bool { return $this->viewAny($user); }
    public function delete(User $user, AiIntegration $integration): bool { return $this->viewAny($user); }
    public function testConnection(User $user, AiIntegration $integration): bool { return $this->viewAny($user); }
}
