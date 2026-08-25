<?php

namespace Database\Factories;

use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseLegalHold;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ComplianceCaseLegalHold> */
class ComplianceCaseLegalHoldFactory extends Factory
{
    public function definition(): array
    {
        $issuer = User::factory()->create();
        $issuer->assignRole('Security Admin');
        $custodian = User::factory()->create();
        $case = ComplianceCase::factory()->create(['opened_by' => $issuer->id]);
        $event = ComplianceCaseEvent::factory()->create(['compliance_case_id' => $case->id, 'recorded_by' => $issuer->id]);
        $issuedAt = now()->startOfSecond();
        $userSnapshot = fn (User $user): array => $user->only(['id', 'name', 'email']) + ['active' => true];
        $case->load(['opener:id,name,email', 'assignee:id,name,email']);
        $caseSnapshot = $case->only([
            'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
            'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
            'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
        ]) + ['opened_by' => $case->opener?->only(['id', 'name', 'email']), 'assigned_to' => null];
        $eventSnapshot = $event->only(['id', 'compliance_case_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot', 'summary', 'recorded_by', 'fingerprint'])
            + ['recorded_at' => $event->recorded_at->toIso8601String()];
        $payload = [
            'compliance_case_id' => $case->id, 'compliance_case_event_id' => $event->id, 'version' => 1,
            'reference' => 'CC-LH-FAC-'.Str::upper(Str::random(12)),
            'scope' => 'Preserve deliberate records relevant to the governed compliance case.',
            'systems' => ['Email', 'Procurement'], 'data_categories' => ['Correspondence', 'Contracts'],
            'legal_basis_reference' => 'Counsel instruction LEGAL-FAC-1', 'preservation_start_at' => $issuedAt->toIso8601String(),
            'issued_by' => $issuer->id, 'issuer_snapshot' => $userSnapshot($issuer),
            'case_snapshot' => $caseSnapshot, 'latest_event_snapshot' => $eventSnapshot,
            'custodian_snapshot' => [$userSnapshot($custodian)], 'issued_at' => $issuedAt->toIso8601String(),
        ];

        return $payload + ['fingerprint' => hash('sha256', CanonicalJson::encode($payload))];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ComplianceCaseLegalHold $hold): void {
            foreach ($hold->custodian_snapshot as $recipient) {
                $hold->custodians()->create(['user_id' => $recipient['id'], 'recipient_snapshot' => $recipient]);
            }
        });
    }
}
