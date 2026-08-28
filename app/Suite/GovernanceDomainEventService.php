<?php

namespace App\Suite;

use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use Illuminate\Support\Str;

class GovernanceDomainEventService
{
    public function __construct(private readonly DataGovernanceControlService $controls) {}

    /** @param array<string, mixed> $envelope */
    public function apply(string $tenantId, string $source, array $envelope, string $raw): string
    {
        $eventType = (string) ($envelope['event_type'] ?? '');
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];

        if (in_array($eventType, ['finance.privacy.opened', 'ppm.privacy.opened'], true)) {
            $sourceRequestRef = (string) ($envelope['entity_id'] ?? '');
            $subjectRef = (string) ($payload['subject_ref'] ?? '');
            $right = (string) ($payload['right'] ?? '');
            if (! Str::isUuid($sourceRequestRef) || ! Str::isUuid($subjectRef)
                || ! in_array($right, ['access', 'correction', 'deletion', 'restriction', 'objection', 'portability'], true)) {
                return 'ignored';
            }
            $this->controls->openPrivacyRequest([
                'tenant_id' => $tenantId, 'source' => $source, 'source_request_ref' => $sourceRequestRef,
                'subject_ref' => $subjectRef, 'right' => $right,
                'lawful_basis' => (string) ($payload['lawful_basis'] ?? 'data_subject_right'),
                'requested_at' => $payload['requested_at'] ?? $envelope['occurred_at'] ?? now(),
            ]);

            return 'governance evidence recorded';
        }

        if (in_array($eventType, ['ppm.records.hold_applied', 'ppm.records.hold_released'], true)) {
            $recordClass = (string) ($payload['record_class_id'] ?? '');
            $recordRef = (string) ($payload['record_ref'] ?? $envelope['entity_id'] ?? '');
            $sourceHoldRef = (string) ($payload['source_hold_ref'] ?? '');
            $retentionDays = (int) ($payload['retention_days'] ?? 0);
            if (! Str::isUuid($recordClass) || ! Str::isUuid($recordRef) || ! Str::isUuid($sourceHoldRef) || $retentionDays < 1) {
                return 'ignored';
            }
            $policy = $this->controls->defineRetentionPolicy([
                'tenant_id' => $tenantId, 'source' => $source, 'record_class' => $recordClass,
                'retention_days' => $retentionDays, 'disposition_action' => 'delete',
            ]);
            if ($eventType === 'ppm.records.hold_applied') {
                $this->controls->placeLegalHold($policy, 'Source application legal hold', $recordRef, $sourceHoldRef);
            } else {
                $hold = LegalHold::query()
                    ->where('source_hold_ref', $sourceHoldRef)
                    ->whereHas('retentionPolicy', fn ($query) => $query->where(['tenant_id' => $tenantId, 'source' => $source]))
                    ->first();
                if ($hold === null) {
                    return 'ignored';
                }
                if ($hold->released_at === null) {
                    $this->controls->releaseLegalHold($hold);
                }
            }

            return 'governance evidence recorded';
        }

        if ($eventType === 'hr.person.purged' && ($payload['erasure'] ?? false) === true) {
            $subjectRef = (string) ($payload['person_uuid'] ?? $envelope['entity_id'] ?? '');
            if (! Str::isUuid($subjectRef)) {
                return 'ignored';
            }
            $request = $this->controls->openPrivacyRequest([
                'tenant_id' => $tenantId,
                'source' => $source,
                'subject_ref' => $subjectRef,
                'right' => 'deletion',
                'lawful_basis' => 'data_subject_or_retention_erasure',
                'requested_at' => $payload['requested_at'] ?? $envelope['occurred_at'] ?? now(),
            ]);
            $this->controls->closePrivacyRequest(
                $request,
                'urn:fynix:hr:erasure:'.$subjectRef,
                hash('sha256', $raw),
            );

            return 'governance evidence recorded';
        }

        if ($eventType === 'hr.person.dsar_exported') {
            $subjectRef = (string) ($payload['person_uuid'] ?? $envelope['entity_id'] ?? '');
            $evidenceRef = (string) ($payload['evidence_ref'] ?? '');
            $evidenceSha = (string) ($payload['evidence_sha256'] ?? '');
            if (! Str::isUuid($subjectRef) || ($payload['right'] ?? null) !== 'access' || ! preg_match('/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', $evidenceRef) || ! preg_match('/^[a-f0-9]{64}$/', $evidenceSha)) {
                return 'ignored';
            }
            $request = $this->controls->openPrivacyRequest([
                'tenant_id' => $tenantId, 'source' => $source, 'subject_ref' => $subjectRef,
                'right' => 'access', 'lawful_basis' => 'data_subject_access',
                'requested_at' => $envelope['occurred_at'] ?? now(),
            ]);
            $this->controls->closePrivacyRequest($request, $evidenceRef, $evidenceSha);

            return 'governance evidence recorded';
        }

        if (in_array($eventType, ['finance.privacy.completed', 'itsm.privacy.erasure_completed', 'ppm.privacy.erasure_completed', 'ppm.privacy.access_completed'], true)) {
            $subjectRef = (string) ($payload['subject_ref'] ?? '');
            $right = (string) ($payload['right'] ?? '');
            $evidenceRef = (string) ($payload['evidence_ref'] ?? '');
            $evidenceSha = (string) ($payload['evidence_sha256'] ?? '');
            if (! Str::isUuid($subjectRef) || ! in_array($right, ['access', 'correction', 'deletion', 'restriction', 'objection', 'portability'], true) || ! preg_match('/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', $evidenceRef) || ! preg_match('/^[a-f0-9]{64}$/', $evidenceSha)) {
                return 'ignored';
            }
            $sourceRequestRef = in_array($eventType, ['finance.privacy.completed', 'ppm.privacy.erasure_completed', 'ppm.privacy.access_completed'], true)
                ? (string) ($envelope['entity_id'] ?? '') : null;
            $request = $sourceRequestRef !== null && Str::isUuid($sourceRequestRef)
                ? PrivacyRequest::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'source_request_ref' => $sourceRequestRef])->first()
                : null;
            $request ??= $this->controls->openPrivacyRequest([
                'tenant_id' => $tenantId, 'source' => $source, 'source_request_ref' => $sourceRequestRef,
                'subject_ref' => $subjectRef, 'right' => $right, 'lawful_basis' => 'data_subject_right',
                'requested_at' => $payload['requested_at'] ?? $envelope['occurred_at'] ?? now(),
            ]);
            if ($request->status === 'closed') {
                return 'governance evidence recorded';
            }
            $this->controls->closePrivacyRequest($request, $evidenceRef, $evidenceSha);

            return 'governance evidence recorded';
        }

        if ($eventType === 'docflow.records.destroyed') {
            $recordRef = (string) ($envelope['entity_id'] ?? '');
            $recordClass = (string) ($payload['record_class'] ?? '');
            $retentionDays = (int) ($payload['retention_days'] ?? 0);
            $createdAt = (string) ($payload['record_created_at'] ?? '');
            $evidenceRef = (string) ($payload['evidence_ref'] ?? '');
            $evidenceSha = (string) ($payload['evidence_sha256'] ?? '');
            if (! Str::isUuid($recordRef) || $recordClass === '' || $retentionDays < 1 || $createdAt === '' || ! preg_match('/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', $evidenceRef) || ! preg_match('/^[a-f0-9]{64}$/', $evidenceSha)) {
                return 'ignored';
            }
            $policy = $this->controls->defineRetentionPolicy([
                'tenant_id' => $tenantId, 'source' => $source, 'record_class' => $recordClass,
                'retention_days' => $retentionDays, 'disposition_action' => 'delete',
            ]);
            $this->controls->recordDisposition($policy, [
                'record_ref' => $recordRef, 'record_created_at' => $createdAt, 'action' => 'delete',
                'evidence_ref' => $evidenceRef, 'evidence_sha256' => $evidenceSha,
            ]);

            return 'governance evidence recorded';
        }

        return 'ignored';
    }
}
