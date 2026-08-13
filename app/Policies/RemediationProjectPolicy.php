<?php

namespace App\Policies;

use App\Models\RemediationProject;
use App\Models\User;

class RemediationProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('List RemediationProjects') || $user->can('Manage Remediation');
    }

    public function view(User $user, RemediationProject $project): bool
    {
        return ($user->can('Read RemediationProjects') || $user->can('Manage Remediation'))
            && $project->isMember($user);
    }

    public function create(User $user): bool
    {
        return $user->can('Create RemediationProjects') || $user->can('Manage Remediation');
    }

    public function update(User $user, RemediationProject $project): bool
    {
        return ($user->can('Update RemediationProjects') || $user->can('Manage Remediation'))
            && $project->isMember($user);
    }

    public function delete(User $user, RemediationProject $project): bool
    {
        return $user->can('Delete RemediationProjects') && $project->isMember($user);
    }
}
