<?php

namespace App\Policies;

use App\Models\GovernedModel;
use App\Models\User;

class GovernedModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Read Model Risk') || $user->can('Manage Model Risk') || $user->can('Validate Models') || $user->can('Own Governed Models') || $user->can('Develop Governed Models');
    }

    public function view(User $user, GovernedModel $model): bool
    {
        return $user->can('Read Model Risk') || $user->can('Manage Model Risk') || $user->can('Validate Models') || ($user->can('Own Governed Models') && $model->owner_id === $user->id) || ($user->can('Develop Governed Models') && $model->developer_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('Manage Model Risk');
    }

    public function update(User $user, GovernedModel $model): bool
    {
        return $user->can('Manage Model Risk') || ($user->can('Own Governed Models') && $model->owner_id === $user->id) || ($user->can('Develop Governed Models') && $model->developer_id === $user->id);
    }

    public function validateModel(User $user, GovernedModel $model): bool
    {
        return $user->can('Validate Models') || $user->can('Manage Model Risk');
    }
}
