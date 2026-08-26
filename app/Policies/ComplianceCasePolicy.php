<?php

namespace App\Policies;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\Models\ComplianceCase;
use App\Models\User;

class ComplianceCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Read Compliance Cases') || $user->can('Manage Compliance Cases') || $user->can('Investigate Compliance Cases');
    }

    public function view(User $user, ComplianceCase $case): bool
    {
        return $user->can('Read Compliance Cases') || $user->can('Manage Compliance Cases')
            || ($user->can('Investigate Compliance Cases') && $case->assigned_to === $user->id)
            || ComplianceCaseAccessGrantManager::granteeCanView($user, $case);
    }

    public function create(User $user): bool
    {
        return $user->can('Manage Compliance Cases');
    }

    public function update(User $user, ComplianceCase $case): bool
    {
        return $user->can('Manage Compliance Cases')
            || ($user->can('Investigate Compliance Cases') && $case->assigned_to === $user->id);
    }
}
