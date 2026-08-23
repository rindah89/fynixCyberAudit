<?php

namespace App\Policies;

use App\Models\BusinessService;
use App\Models\User;
use App\Support\Enterprise;

class BusinessServicePolicy
{
    public function viewAny(User $user): bool
    {
        return Enterprise::enabled('resilience') && ($user->can('Manage Resilience') || BusinessService::where('owner_id', $user->id)->exists());
    }

    public function view(User $user, BusinessService $service): bool
    {
        return Enterprise::enabled('resilience') && ($user->can('Manage Resilience') || $service->owner_id === $user->id);
    }

    public function create(User $user): bool
    {
        return Enterprise::enabled('resilience') && $user->can('Manage Resilience');
    }

    public function update(User $user, BusinessService $service): bool
    {
        return Enterprise::enabled('resilience') && $user->can('Manage Resilience');
    }

    public function delete(User $user, BusinessService $service): bool
    {
        return Enterprise::enabled('resilience') && $user->can('Manage Resilience');
    }
}
