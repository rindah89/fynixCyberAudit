<?php

namespace App\Policies;

use App\Models\PrivacyRightsRequest;
use App\Models\User;
use App\Support\Enterprise;

class PrivacyRightsRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return Enterprise::enabled('privacy_management') && ($user->can('Read Privacy Rights') || $user->can('Manage Privacy Rights') || $user->can('Handle Privacy Rights'));
    }

    public function view(User $user, PrivacyRightsRequest $request): bool
    {
        return Enterprise::enabled('privacy_management') && ($user->can('Read Privacy Rights') || $user->can('Manage Privacy Rights') || ($user->can('Handle Privacy Rights') && $request->assigned_to === $user->id));
    }

    public function create(User $user): bool
    {
        return Enterprise::enabled('privacy_management') && $user->can('Manage Privacy Rights');
    }

    public function update(User $user, PrivacyRightsRequest $request): bool
    {
        return Enterprise::enabled('privacy_management') && ($user->can('Manage Privacy Rights') || ($user->can('Handle Privacy Rights') && $request->assigned_to === $user->id));
    }
}
