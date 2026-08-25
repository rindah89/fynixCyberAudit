<?php

namespace App\Policies;

use App\Enums\ComplianceCaseIntakeAudience;
use App\Models\ComplianceCaseIntakeMessage;
use App\Models\User;

class ComplianceCaseIntakeMessagePolicy
{
    public function view(User $user, ComplianceCaseIntakeMessage $message): bool
    {
        $message->loadMissing('intake');

        return $message->intake !== null && $user->can('view', $message->intake);
    }

    public function create(User $user): bool
    {
        return $user->can('Manage Compliance Cases') || ! $user->trashed();
    }

    public function acknowledge(User $user, ComplianceCaseIntakeMessage $message): bool
    {
        $message->loadMissing('intake');

        return $message->audience === ComplianceCaseIntakeAudience::Reporter
            && $message->intake !== null
            && (int) $message->intake->submitted_by === (int) $user->id
            && ! $user->trashed();
    }
}
