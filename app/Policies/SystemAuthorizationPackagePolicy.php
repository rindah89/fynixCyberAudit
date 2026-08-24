<?php

namespace App\Policies;

use App\Models\SystemAuthorizationPackage;
use App\Models\User;

class SystemAuthorizationPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Read System Authorizations') || $user->can('Manage System Authorizations') || $user->can('Authorize Systems');
    }

    public function view(User $user, SystemAuthorizationPackage $package): bool
    {
        return $this->viewAny($user) || $package->application?->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('Manage System Authorizations');
    }

    public function decide(User $user, SystemAuthorizationPackage $package): bool
    {
        return $user->can('Authorize Systems');
    }
}
