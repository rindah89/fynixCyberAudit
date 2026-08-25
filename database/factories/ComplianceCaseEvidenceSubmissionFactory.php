<?php

namespace Database\Factories;

use App\Enums\ComplianceCaseStatus;
use App\Enums\ResponseStatus;
use App\Models\Audit;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseEvidenceSubmission;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/** @extends Factory<ComplianceCaseEvidenceSubmission> */
class ComplianceCaseEvidenceSubmissionFactory extends Factory
{
    public function definition(): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('Investigate Compliance Cases');
        $case = ComplianceCase::factory()->create(['opened_by' => $actor->id]);
        $opening = ComplianceCaseEvent::factory()->create([
            'compliance_case_id' => $case->id,
            'recorded_by' => $actor->id,
        ]);
        $case->update(['assigned_to' => $actor->id, 'status' => ComplianceCaseStatus::Triaged]);
        $event = ComplianceCaseEvent::factory()->create([
            'compliance_case_id' => $case->id,
            'version' => 2,
            'before_snapshot' => $opening->after_snapshot,
            'recorded_by' => $actor->id,
        ]);
        $audit = Audit::factory()->create(['manager_id' => $actor->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id,
            'created_by_id' => $actor->id,
            'assigned_to_id' => $actor->id,
        ]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id,
            'requester_id' => $actor->id,
            'requestee_id' => $actor->id,
        ]);
        $contents = 'factory compliance evidence';
        $attachment = FileAttachment::factory()->create([
            'data_request_response_id' => $response->id,
            'audit_id' => $audit->id,
            'file_name' => 'factory-compliance-evidence.txt',
            'file_path' => 'factory/compliance-evidence.txt',
            'file_size' => strlen($contents),
            'description' => 'Canonical factory compliance-case evidence.',
            'uploaded_by' => $actor->id,
        ]);
        $recordedAt = now()->startOfSecond();
        $retainedPath = 'governed-evidence/compliance-cases/factory/'.$attachment->id;
        Storage::disk('private')->put($attachment->file_path, $contents);
        Storage::disk('private')->put($retainedPath, $contents);
        $manifest = [[
            'file_attachment_id' => $attachment->id,
            'data_request_response_id_snapshot' => $response->id,
            'response_status_snapshot' => ResponseStatus::ACCEPTED->value,
            'data_request_id_snapshot' => $request->id,
            'audit_id_snapshot' => $audit->id,
            'disk_snapshot' => 'private',
            'file_name_snapshot' => $attachment->file_name,
            'file_path_snapshot' => $retainedPath,
            'file_size_snapshot' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]];
        $payload = [
            'compliance_case_id' => $case->id,
            'version' => 1,
            'summary' => 'Canonical governed compliance-case evidence submission.',
            'case_snapshot' => $event->after_snapshot,
            'latest_event_snapshot' => $event->attributesToArray(),
            'evidence_manifest' => $manifest,
            'recorded_by' => $actor->id,
            'actor_snapshot' => $actor->only(['id', 'name', 'email']),
            'recorded_at' => $recordedAt->toIso8601String(),
        ];

        return $payload + ['fingerprint' => hash('sha256', CanonicalJson::encode($payload))];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ComplianceCaseEvidenceSubmission $submission): void {
            foreach ($submission->evidence_manifest as $snapshot) {
                $submission->evidence()->create($snapshot + [
                    'linked_by' => $submission->recorded_by,
                    'linked_at' => $submission->recorded_at,
                ]);
            }
        });
    }
}
