<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('List Incidents') || $user->can('Manage Incidents');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('Read Incidents') || $user->can('Manage Incidents');
    }

    public function create(User $user): bool
    {
        return $user->can('Create Incidents') || $user->can('Manage Incidents');
    }

    public function update(User $user, Incident $incident): bool
    {
        return $user->can('Update Incidents') || $user->can('Manage Incidents');
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $user->can('Delete Incidents');
    }
}
