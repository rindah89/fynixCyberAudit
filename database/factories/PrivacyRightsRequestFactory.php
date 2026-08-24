<?php

namespace Database\Factories;

use App\Enums\PrivacyRightsRequestStatus;
use App\Enums\PrivacyRightsRequestType;
use App\Models\PrivacyRightsRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrivacyRightsRequestFactory extends Factory
{
    protected $model = PrivacyRightsRequest::class;

    public function definition(): array
    {
        return ['number' => fake()->unique()->numerify('PRR-2026-######'), 'request_type' => PrivacyRightsRequestType::Access,
            'status' => PrivacyRightsRequestStatus::Received, 'data_subject_name' => fake()->name(), 'data_subject_email' => fake()->safeEmail(),
            'subject_reference' => fake()->uuid(), 'request_details' => fake()->paragraph(), 'intake_channel' => 'Authenticated operator intake',
            'jurisdiction_reference' => 'Operator supplied', 'received_at' => now(), 'due_at' => now()->addDays(30),
            'assigned_to' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Handle Privacy Rights')),
            'opened_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Privacy Rights')), 'governed_at' => now()];
    }
}
