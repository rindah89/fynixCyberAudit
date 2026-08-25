<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldRelease;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseLegalHoldRelease> */
class ComplianceCaseLegalHoldReleaseFactory extends Factory
{
    public function definition(): array
    {
        $hold = ComplianceCaseLegalHold::factory()->create();
        $custodian = $hold->custodians()->firstOrFail();
        $acknowledgement = app(ComplianceCaseLegalHoldManager::class)->acknowledge(
            $custodian->user,
            $hold,
            ['statement' => 'I acknowledge this preservation instruction.'],
        );
        $actor = User::factory()->create();
        $actor->assignRole('Security Admin');
        $at = now()->startOfSecond();
        $custodianEvidence = [[
            'id' => $custodian->id, 'user_id' => $custodian->user_id,
            'recipient_snapshot' => $custodian->recipient_snapshot, 'active_at_release' => true,
            'acknowledgement' => app(ComplianceCaseLegalHoldManager::class)->acknowledgementSnapshot($acknowledgement),
        ]];
        $payload = [
            'compliance_case_legal_hold_id' => $hold->id, 'released_by' => $actor->id,
            'actor_snapshot' => $actor->only(['id', 'name', 'email']) + ['active' => true],
            'hold_snapshot' => app(ComplianceCaseLegalHoldManager::class)->holdSnapshot($hold),
            'custodian_acknowledgement_snapshot' => $custodianEvidence,
            'summary' => 'Independent review confirms the internal preservation instruction can be released.',
            'released_at' => $at->toIso8601String(),
        ];

        return $payload + ['fingerprint' => hash('sha256', CanonicalJson::encode($payload))];
    }
}
