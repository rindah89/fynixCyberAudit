<?php

namespace App\Policies;

use App\Models\RiskAssessment;
use App\Models\User;

class RiskAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('List RiskAssessments') || $user->can('Manage Risk Assessments');
    }

    public function view(User $user, RiskAssessment $assessment): bool
    {
        return ($user->can('Read RiskAssessments') || $user->can('Manage Risk Assessments'))
            && $assessment->isCollaborator($user);
    }

    public function create(User $user): bool
    {
        return $user->can('Create RiskAssessments') || $user->can('Manage Risk Assessments');
    }

    public function update(User $user, RiskAssessment $assessment): bool
    {
        return ($user->can('Update RiskAssessments') || $user->can('Manage Risk Assessments'))
            && $assessment->isCollaborator($user);
    }

    public function delete(User $user, RiskAssessment $assessment): bool
    {
        return $user->can('Delete RiskAssessments') && $assessment->isCollaborator($user);
    }
}
