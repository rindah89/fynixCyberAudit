<?php

namespace App\Policies;

use App\Models\ControlTestDefinition;
use App\Models\User;

class ControlTestDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('List Controls') || ControlTestDefinition::query()
            ->where('owner_id', $user->id)
            ->orWhereHas('control', fn ($query) => $query->where('control_owner_id', $user->id))
            ->exists();
    }

    public function view(User $user, ControlTestDefinition $definition): bool
    {
        return $user->can('Read Controls') || $this->isOwner($user, $definition);
    }

    public function create(User $user): bool
    {
        return $user->can('Update Controls');
    }

    public function update(User $user, ControlTestDefinition $definition): bool
    {
        return $user->can('Update Controls');
    }

    public function delete(User $user, ControlTestDefinition $definition): bool
    {
        return $user->can('Delete Controls');
    }

    public function execute(User $user, ControlTestDefinition $definition): bool
    {
        return $user->can('Update Controls') || $this->isOwner($user, $definition);
    }

    private function isOwner(User $user, ControlTestDefinition $definition): bool
    {
        return $user->id === $definition->owner_id || $user->id === $definition->control->control_owner_id;
    }
}
