<?php

namespace App\Policies;

use App\Models\ThirdPartyCollaborationClosureAcknowledgementDelivery;
use App\Models\User;

class ThirdPartyCollaborationClosureAcknowledgementDeliveryPolicy
{
    public function acknowledge(User $user, ThirdPartyCollaborationClosureAcknowledgementDelivery $delivery): bool
    {
        return (int) $user->id === (int) $delivery->user_id;
    }
}
