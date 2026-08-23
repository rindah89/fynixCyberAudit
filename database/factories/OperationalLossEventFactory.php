<?php

namespace Database\Factories;

use App\Enums\OperationalLossEventCategory;
use App\Models\BusinessService;
use App\Models\OperationalLossEvent;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperationalLossEventFactory extends Factory
{
    protected $model = OperationalLossEvent::class;

    public function definition(): array
    {
        return [
            'risk_id' => Risk::factory(),
            'business_service_id_snapshot' => BusinessService::factory(),
            'business_service_snapshot' => fn (array $attributes): array => BusinessService::query()
                ->findOrFail($attributes['business_service_id_snapshot'])
                ->only(['id', 'owner_id', 'code', 'name', 'criticality', 'status']),
            'reported_by' => User::factory(),
            'category' => OperationalLossEventCategory::BusinessDisruptionSystemFailure,
            'occurred_at' => today()->subDay(),
            'detected_at' => today(),
            'summary' => fake()->sentence(),
            'gross_loss' => '1000.00',
            'recoveries' => '100.00',
            'net_loss' => '900.00',
            'currency' => 'USD',
            'recorded_at' => now(),
        ];
    }
}
