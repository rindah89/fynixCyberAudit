<?php

namespace App\Policies;

use App\Models\EsgDisclosure;
use App\Models\User;

class EsgDisclosurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('Read ESG') || $user->can('Manage ESG') || $user->can('Validate ESG Data') || $user->can('Approve ESG Disclosures');
    }

    public function view(User $user, EsgDisclosure $disclosure): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('Manage ESG');
    }

    public function decide(User $user, EsgDisclosure $disclosure): bool
    {
        return $user->can('Approve ESG Disclosures') || $user->can('Manage ESG');
    }
}
