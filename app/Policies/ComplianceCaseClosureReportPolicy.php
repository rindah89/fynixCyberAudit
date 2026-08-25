<?php

namespace App\Policies;

use App\Models\User;

class ComplianceCaseClosureReportPolicy extends ComplianceCaseOwnedPolicy
{
    public function create(User $user): bool
    {
        return $user->can('Manage Compliance Cases');
    }

    public function update(User $user, $record): bool
    {
        $case = $this->case($record);

        return $case !== null && $user->can('Manage Compliance Cases') && $user->can('view', $case);
    }
}
