<?php

namespace App\Suite;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GovernanceStatementPublisher
{
    public function __construct(private readonly DataGovernanceControlService $controls) {}

    /** @return array<string, mixed> */
    public function build(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $observedAt = $now->format(\DateTimeInterface::ATOM);
        $tenantId = (string) config('data_governance.publisher.tenant_id');
        $recoveryCurrent = $tenantId !== '' && $this->controls->hasCurrentRecoveryEvidence($tenantId, 'cyberaudit', $now);
        $processorRegisterCurrent = $tenantId !== '' && $this->controls->hasCurrentProcessorRegister($tenantId, 'cyberaudit', $now);
        $definitions = [
            'DG-01' => ['effective', 'Standards, controls, implementations, applications and assets form an explicit governance inventory.', ['CONTEXT.md', 'app/Models/Application.php'], ['application_register' => true]],
            'DG-02' => ['effective', 'Applications, assets, evidence and trust-centre documents have explicit protected handling paths.', ['app/Access/FileAccess.php', 'app/Models/Asset.php'], ['private_file_gateway' => true]],
            'DG-03' => ['partially_effective', 'Authenticated access export requires controlled identity-verification evidence, fails closed on an incomplete relationship registry, and records digest-bound CyberAudit review evidence; correction, restriction, objection, and erasure remain incomplete.', ['app/Suite/CyberAuditPrivacyExportController.php', 'app/Suite/CyberAuditPrivacyExportService.php', 'app/Models/PrivacyRequest.php'], ['identity_verification_enforced' => true, 'access_discovery_registry' => true, 'digest_bound_fulfilment' => true, 'erasure_fulfilment' => false]],
            'DG-04' => ['effective', 'Spatie permissions, policies and separated staff/vendor identities enforce least privilege.', ['database/seeders/RolePermissionSeeder.php', 'app/Policies', 'CONTEXT.md#identity'], ['separate_vendor_identity' => true]],
            'DG-05' => ['effective', 'Authentication, audit, evidence authorization and suite receipts are logged and retained.', ['app/Services/AppLogger.php', 'app/Models/GovernanceStatement.php'], ['signed_governance_receipts' => true]],
            'DG-06' => ['partially_effective', 'Retention eligibility is derived from record age and legal holds block receipts, but source applications must still execute and independently evidence disposition.', ['app/Models/RetentionPolicy.php', 'app/Models/LegalHold.php', 'app/Suite/DataGovernanceControlService.php'], ['eligibility_calculated' => true, 'legal_hold_blocks_disposition' => true, 'source_disposition_execution' => false]],
            'DG-07' => ['effective', 'Control results retain source, period, evidence references, measurements and a payload digest.', ['app/Suite/GovernanceEvidenceService.php'], ['normalized_control_results' => true]],
            'DG-08' => ['effective', 'Dedicated HMAC secrets, private evidence access and encrypted transport boundaries protect data.', ['app/Suite/SuiteEnvelope.php', 'docs/deployment/suite-data-governance.md'], ['shared_application_tokens' => false]],
            'DG-09' => [$recoveryCurrent ? 'effective' : 'partially_effective', $recoveryCurrent ? 'A current restore drill has independently reviewed, digest-bound evidence.' : 'No current independently approved restore-drill evidence is registered.', ['docs/DEPLOYMENT_AGENT.md#versioning-backup-and-rollback', 'app/Models/RecoveryEvidence.php', 'app/Models/GovernanceControlReview.php'], ['independent_restore_verification' => $recoveryCurrent]],
            'DG-10' => ['effective', 'Incident, breach-notification, remediation and ITSM integration workflows are available.', ['app/Incidents', 'app/Suite/ItsmGateway.php'], ['incident_workflow' => true]],
            'DG-11' => [$processorRegisterCurrent ? 'effective' : 'partially_effective', $processorRegisterCurrent ? 'Every processor entry is approved and the complete inventory has a current independent certification.' : 'Processor entries require approval and a complete-register certification.', ['app/Models/DataProcessor.php', 'app/Models/ProcessorRegisterCertification.php'], ['certified_processor_register' => $processorRegisterCurrent]],
            'DG-12' => ['effective', 'Automated tests, static analysis, dependency locking, SBOM generation and deployment gates are maintained.', ['composer.json', 'composer.lock', 'generate-sbom.php'], ['sbom_generation' => true]],
        ];
        $controls = [];
        foreach ($definitions as $controlId => [$status, $summary, $references, $metrics]) {
            $controls[] = ['control_id' => $controlId, 'status' => $status, 'observed_at' => $observedAt, 'summary' => $summary, 'evidence_refs' => $references, 'metrics' => $metrics];
        }

        return [
            'event_type' => 'governance.evidence.reported',
            'tenant_id' => $tenantId,
            'entity_type' => 'governance_statement',
            'entity_id' => (string) Str::uuid(),
            'occurred_at' => $observedAt,
            'payload' => [
                'schema_version' => config('data_governance.schema_version'),
                'period_start' => $now->modify('-1 day')->format(\DateTimeInterface::ATOM),
                'period_end' => $observedAt,
                'controls' => $controls,
            ],
        ];
    }

    /** @return array{outcome: string, statement_id: string} */
    public function publish(): array
    {
        $endpoint = (string) config('data_governance.publisher.endpoint');
        $webhookId = (string) config('data_governance.publisher.webhook_id');
        $secret = (string) config('data_governance.publisher.secret');
        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $secureEndpoint = $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
        if (! $secureEndpoint || blank($webhookId) || strlen($secret) < 32 || blank(config('data_governance.publisher.tenant_id'))) {
            throw new InvalidArgumentException('Cyber Audit governance publisher binding is incomplete or insecure.');
        }
        $statement = $this->build();
        $raw = json_encode($statement, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $deliveryId = (string) Str::uuid();
        $signature = SuiteEnvelope::sign($secret, $timestamp, 'governance.evidence.reported', 'cyberaudit', $webhookId, $deliveryId, $raw);
        $response = $this->client()->withHeaders([
            'X-Fynix-Timestamp' => (string) $timestamp,
            'X-Fynix-Event' => 'governance.evidence.reported',
            'X-Fynix-Source' => 'cyberaudit',
            'X-Fynix-Webhook-Id' => $webhookId,
            'X-Fynix-Delivery-Id' => $deliveryId,
            'X-Fynix-Signature' => $signature,
        ])->withBody($raw, 'application/json')->post($endpoint);
        if (! $response->successful()) {
            throw new RuntimeException('Cyber Audit governance receiver returned '.$response->status().'.');
        }
        $receipt = $response->json();
        if (($receipt['outcome'] ?? null) !== 'recorded' || ($receipt['statement_id'] ?? null) !== $statement['entity_id']) {
            throw new RuntimeException('Cyber Audit returned an invalid governance receipt.');
        }

        return ['outcome' => $receipt['outcome'], 'statement_id' => $receipt['statement_id']];
    }

    protected function client(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->withoutRedirecting();
    }
}
