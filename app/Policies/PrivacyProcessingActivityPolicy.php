<?php

namespace App\Policies;

use App\Models\PrivacyProcessingActivity;
use App\Models\User;

class PrivacyProcessingActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Read Privacy') || $user->can('Manage Privacy') || $user->can('Assess Privacy') || $user->can('Own Privacy Activities');
    }

    public function view(User $user, PrivacyProcessingActivity $activity): bool
    {
        return $user->can('Read Privacy') || $user->can('Manage Privacy') || $user->can('Assess Privacy') || ($user->can('Own Privacy Activities') && $activity->owner_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('Manage Privacy');
    }

    public function update(User $user, PrivacyProcessingActivity $activity): bool
    {
        return $user->can('Manage Privacy') || ($user->can('Own Privacy Activities') && $activity->owner_id === $user->id);
    }

    public function assess(User $user, PrivacyProcessingActivity $activity): bool
    {
        return $user->can('Assess Privacy') || $user->can('Manage Privacy');
    }
}
