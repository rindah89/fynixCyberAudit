<?php

namespace App\Policies;

use App\Models\ComplianceCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ComplianceCaseOwnedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', ComplianceCase::class);
    }

    public function view(User $user, Model $record): bool
    {
        $case = $this->case($record);

        return $case !== null && $user->can('view', $case);
    }

    public function create(User $user): bool
    {
        return $user->can('Manage Compliance Cases') || $user->can('Investigate Compliance Cases');
    }

    public function update(User $user, Model $record): bool
    {
        $case = $this->case($record);

        return $case !== null && $user->can('update', $case);
    }

    protected function case(Model $record): ?ComplianceCase
    {
        if ($record instanceof ComplianceCase) {
            return $record;
        }

        $case = $record->complianceCase;

        return $case instanceof ComplianceCase ? $case : null;
    }
}
