<?php

namespace Database\Factories;

use App\Models\PolicyAcknowledgementAssignment;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyAcknowledgementAssignmentFactory extends Factory
{
    protected $model = PolicyAcknowledgementAssignment::class;

    public function definition(): array
    {
        return [
            'policy_acknowledgement_campaign_id' => PolicyAcknowledgementCampaign::factory(),
            'user_id' => User::factory(),
            'assigned_at' => now(),
        ];
    }
}
