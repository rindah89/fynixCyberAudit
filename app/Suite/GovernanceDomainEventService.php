<?php

namespace App\Suite;

use Illuminate\Support\Str;

class GovernanceDomainEventService
{
    public function __construct(private readonly DataGovernanceControlService $controls) {}

    /** @param array<string, mixed> $envelope */
    public function apply(string $tenantId, string $source, array $envelope, string $raw): string
    {
        $eventType = (string) ($envelope['event_type'] ?? '');
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];

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
                'requested_at' => $envelope['occurred_at'] ?? now(),
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
