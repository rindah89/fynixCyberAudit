<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldAcknowledgement;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseLegalHoldAcknowledgement> */
class ComplianceCaseLegalHoldAcknowledgementFactory extends Factory
{
    public function definition(): array
    {
        $hold = ComplianceCaseLegalHold::factory()->create();
        $custodian = $hold->custodians()->firstOrFail();
        $at = now()->startOfSecond();
        $holdSnapshot = app(ComplianceCaseLegalHoldManager::class)->holdSnapshot($hold);
        $payload = [
            'compliance_case_legal_hold_id' => $hold->id,
            'compliance_case_legal_hold_custodian_id' => $custodian->id, 'user_id' => $custodian->user_id,
            'hold_snapshot' => $holdSnapshot, 'recipient_snapshot' => $custodian->recipient_snapshot,
            'statement' => 'I acknowledge this preservation instruction.', 'comment' => null,
            'acknowledged_at' => $at->toIso8601String(),
        ];

        return $payload + ['fingerprint' => hash('sha256', CanonicalJson::encode($payload))];
    }
}
