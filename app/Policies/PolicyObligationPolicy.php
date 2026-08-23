<?php

namespace App\Policies;

use App\Models\PolicyObligation;
use App\Models\User;

class PolicyObligationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('List Policies')
            || PolicyObligation::query()
                ->where('owner_id', $user->id)
                ->orWhereHas('policy', fn ($query) => $query->where('owner_id', $user->id))
                ->exists();
    }

    public function view(User $user, PolicyObligation $obligation): bool
    {
        return $user->can('Read Policies')
            || $user->id === $obligation->owner_id
            || $user->id === $obligation->policy->owner_id;
    }

    public function create(User $user): bool
    {
        return $user->can('Update Policies');
    }

    public function update(User $user, PolicyObligation $obligation): bool
    {
        return $user->can('Update Policies') || $user->id === $obligation->policy->owner_id;
    }

    public function delete(User $user, PolicyObligation $obligation): bool
    {
        return $user->can('Delete Policies') || $user->id === $obligation->policy->owner_id;
    }

    public function attest(User $user, PolicyObligation $obligation): bool
    {
        return $user->id === $obligation->owner_id
            || $user->id === $obligation->policy->owner_id
            || $user->can('Update Policies');
    }
}
