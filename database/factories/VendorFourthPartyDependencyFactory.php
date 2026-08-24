<?php

namespace Database\Factories;

use App\Enums\FourthPartyCriticality;
use App\Enums\FourthPartyDependencyCategory;
use App\Enums\FourthPartyDependencyStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFourthPartyDependency;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFourthPartyDependencyFactory extends Factory
{
    protected $model = VendorFourthPartyDependency::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'vendor_id' => Vendor::factory(),
            'recorded_by' => User::factory(),
            'dependency_key' => 'external:'.hash('sha256', str($name)->lower()->squish()->toString()),
            'version' => 1,
            'status' => FourthPartyDependencyStatus::Active,
            'category' => FourthPartyDependencyCategory::TechnologyService,
            'criticality' => FourthPartyCriticality::Medium,
            'fourth_party_name' => $name,
            'service_description' => fake()->sentence(),
            'data_access' => false,
            'rationale' => fake()->sentence(),
            'governance_snapshot' => fn (array $attributes): array => [
                'primary_vendor' => Vendor::query()->findOrFail($attributes['vendor_id'])
                    ->only(['id', 'name', 'vendor_manager_id', 'status', 'risk_rating']),
                'fourth_party' => ['id' => null, 'name' => $attributes['fourth_party_name']],
                'business_service' => null,
                'dependency' => [
                    'status' => $attributes['status'] instanceof FourthPartyDependencyStatus ? $attributes['status']->value : $attributes['status'],
                    'category' => $attributes['category'] instanceof FourthPartyDependencyCategory ? $attributes['category']->value : $attributes['category'],
                    'criticality' => $attributes['criticality'] instanceof FourthPartyCriticality ? $attributes['criticality']->value : $attributes['criticality'],
                    'service_description' => $attributes['service_description'],
                    'data_access' => (bool) $attributes['data_access'],
                ],
            ],
            'recorded_at' => now(),
        ];
    }
}
