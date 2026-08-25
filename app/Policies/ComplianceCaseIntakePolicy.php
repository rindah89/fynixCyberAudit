<?php

namespace App\Policies;

use App\Models\ComplianceCaseIntake;
use App\Models\User;

class ComplianceCaseIntakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Manage Compliance Cases');
    }

    public function view(User $user, ComplianceCaseIntake $intake): bool
    {
        return $user->can('Manage Compliance Cases') || (int) $intake->submitted_by === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return ! $user->trashed();
    }

    public function update(User $user, ComplianceCaseIntake $intake): bool
    {
        return $user->can('Manage Compliance Cases');
    }
}
