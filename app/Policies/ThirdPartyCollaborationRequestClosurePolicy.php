<?php

namespace App\Policies;

use App\Models\ThirdPartyCollaborationRequestClosure;
use App\Models\User;

class ThirdPartyCollaborationRequestClosurePolicy
{
    public function view(User $user, ThirdPartyCollaborationRequestClosure $closure): bool
    {
        return $user->isSuperAdmin() || $user->can('Manage Third Party Risk') || $user->can('Read Vendors');
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('Manage Third Party Risk');
    }

    public function update(User $user, ThirdPartyCollaborationRequestClosure $closure): bool
    {
        return $this->create($user);
    }
}
