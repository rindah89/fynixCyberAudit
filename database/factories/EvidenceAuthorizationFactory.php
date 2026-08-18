<?php

namespace Database\Factories;

use App\Models\EvidenceAuthorization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvidenceAuthorization> */
class EvidenceAuthorizationFactory extends Factory
{
    protected $model = EvidenceAuthorization::class;

    public function definition(): array
    {
        return [
            'profile' => 'fynix-cyberaudit/deploy-release',
            'company_id' => 1,
            'suite_tenant_id' => fake()->uuid(),
            'customer_id' => fake()->uuid(),
            'requester_key_id' => 'factory-requester',
            'authority_binding_version' => 1,
            'request_id' => fake()->uuid(),
            'operation_id' => fake()->uuid(),
            'request_digest' => hash('sha256', fake()->uuid()),
            'request_json' => [],
            'status' => 'pending',
            'retention_until' => now()->addYears(7),
        ];
    }
}
