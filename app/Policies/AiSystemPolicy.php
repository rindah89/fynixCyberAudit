<?php

namespace App\Policies;

use App\Models\AiSystem;
use App\Models\User;

class AiSystemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Manage AI Governance') || AiSystem::query()->where('owner_id', $user->id)->exists();
    }

    public function view(User $user, AiSystem $system): bool
    {
        return $user->can('Manage AI Governance') || $system->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('Manage AI Governance');
    }

    public function update(User $user, AiSystem $system): bool
    {
        return $user->can('Manage AI Governance');
    }

    public function delete(User $user, AiSystem $system): bool
    {
        return $user->can('Manage AI Governance');
    }
}
