<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseAccessGrant;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseAccessGrant> */
class ComplianceCaseAccessGrantFactory extends Factory
{
    protected $model = ComplianceCaseAccessGrant::class;

    public function definition(): array
    {
        $grantor = User::factory()->create();
        $grantor->assignRole('Security Admin');
        $grantee = User::factory()->create();
        $case = ComplianceCase::factory()->create(['opened_by' => $grantor->id]);
        $grantedAt = now()->startOfSecond();

        return [
            'compliance_case_id' => $case->id, 'version' => 1, 'grantee_id' => $grantee->id,
            'grantee_snapshot' => $grantee->only(['id', 'name', 'email']),
            'purpose' => 'Factory need-to-know case review.', 'starts_at' => $grantedAt->copy()->subMinute(),
            'ends_at' => $grantedAt->copy()->addMonth(), 'granted_by' => $grantor->id,
            'grantor_snapshot' => $grantor->only(['id', 'name', 'email']), 'granted_at' => $grantedAt,
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseAccessGrant $grant): void {
            $grant->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseAccessGrantManager::class)->payload($grant),
            ));
        });
    }
}
