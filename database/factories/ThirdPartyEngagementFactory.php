<?php

namespace Database\Factories;

use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\VendorStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyEngagementFactory extends Factory
{
    protected $model = ThirdPartyEngagement::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory()->state(['status' => VendorStatus::ACCEPTED]),
            'code' => fake()->unique()->bothify('ENG-####'),
            'name' => fake()->company().' service engagement',
            'service_description' => fake()->paragraph(),
            'business_owner_id' => User::factory(),
            'criticality' => 'high',
            'data_access' => true,
            'status' => ThirdPartyEngagementStatus::Proposed,
            'proposed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')),
            'term_start_at' => today(),
            'term_end_at' => today()->addYear(),
            'next_review_at' => today()->addMonths(6),
            'vendor_snapshot' => fn (array $attributes): array => Vendor::withTrashed()->findOrFail($attributes['vendor_id'])->only(['id', 'name', 'description', 'url', 'vendor_manager_id', 'contact_name', 'contact_email', 'contact_phone', 'address', 'status', 'risk_rating']),
            'governed_at' => now()->startOfSecond(),
        ];
    }
}
