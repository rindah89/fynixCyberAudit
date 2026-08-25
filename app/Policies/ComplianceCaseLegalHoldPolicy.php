<?php

namespace App\Policies;

use App\Models\ComplianceCaseLegalHold;
use App\Models\User;

class ComplianceCaseLegalHoldPolicy extends ComplianceCaseOwnedPolicy
{
    public function acknowledge(User $user, ComplianceCaseLegalHold $hold): bool
    {
        return ! $user->trashed();
    }
}
