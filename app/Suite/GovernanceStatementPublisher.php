<?php

namespace App\Suite;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GovernanceStatementPublisher
{
    /** @return array<string, mixed> */
    public function build(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $observedAt = $now->format(\DateTimeInterface::ATOM);
        $definitions = [
            'DG-01' => ['effective', 'Standards, controls, implementations, applications and assets form an explicit governance inventory.', ['CONTEXT.md', 'app/Models/Application.php'], ['application_register' => true]],
            'DG-02' => ['effective', 'Applications, assets, evidence and trust-centre documents have explicit protected handling paths.', ['app/Access/FileAccess.php', 'app/Models/Asset.php'], ['private_file_gateway' => true]],
            'DG-03' => ['partially_effective', 'Privacy-oriented data requests and access controls exist; the full Cameroon privacy rights workflow is not automated.', ['app/Models/DataRequest.php', 'app/Access/DataRequestFulfillment.php'], ['complete_privacy_rights_workflow' => false]],
            'DG-04' => ['effective', 'Spatie permissions, policies and separated staff/vendor identities enforce least privilege.', ['database/seeders/RolePermissionSeeder.php', 'app/Policies', 'CONTEXT.md#identity'], ['separate_vendor_identity' => true]],
            'DG-05' => ['effective', 'Authentication, audit, evidence authorization and suite receipts are logged and retained.', ['app/Services/AppLogger.php', 'app/Models/GovernanceStatement.php'], ['signed_governance_receipts' => true]],
            'DG-06' => ['partially_effective', 'Audit evidence and suite state are retained through rollback; configurable disposition for all application records is incomplete.', ['docs/DEPLOYMENT_AGENT.md#versioning-backup-and-rollback'], ['suite_evidence_preserved_on_rollback' => true]],
            'DG-07' => ['effective', 'Control results retain source, period, evidence references, measurements and a payload digest.', ['app/Suite/GovernanceEvidenceService.php'], ['normalized_control_results' => true]],
            'DG-08' => ['effective', 'Dedicated HMAC secrets, private evidence access and encrypted transport boundaries protect data.', ['app/Suite/SuiteEnvelope.php', 'docs/deployment/suite-data-governance.md'], ['shared_application_tokens' => false]],
            'DG-09' => ['partially_effective', 'Backup, restore, RPO and RTO requirements exist; the temporary AWS proof environment lacks scheduled backups.', ['docs/DEPLOYMENT_AGENT.md#versioning-backup-and-rollback'], ['production_proof_scheduled_backups' => false]],
            'DG-10' => ['effective', 'Incident, breach-notification, remediation and ITSM integration workflows are available.', ['app/Incidents', 'app/Suite/ItsmGateway.php'], ['incident_workflow' => true]],
            'DG-11' => ['partially_effective', 'Vendor risk and dedicated suite credentials exist; the processor and transfer register is not fully automated.', ['app/Models/Vendor.php', 'docs/DEPLOYMENT_AGENT.md#cross-application-safety'], ['vendor_risk_register' => true]],
            'DG-12' => ['effective', 'Automated tests, static analysis, dependency locking, SBOM generation and deployment gates are maintained.', ['composer.json', 'composer.lock', 'generate-sbom.php'], ['sbom_generation' => true]],
        ];
        $controls = [];
        foreach ($definitions as $controlId => [$status, $summary, $references, $metrics]) {
            $controls[] = ['control_id' => $controlId, 'status' => $status, 'observed_at' => $observedAt, 'summary' => $summary, 'evidence_refs' => $references, 'metrics' => $metrics];
        }

        return [
            'event_type' => 'governance.evidence.reported',
            'tenant_id' => (string) config('data_governance.publisher.tenant_id'),
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
        if ((! str_starts_with($endpoint, 'https://') && ! str_starts_with($endpoint, 'http://localhost')) || blank($webhookId) || strlen($secret) < 32 || blank(config('data_governance.publisher.tenant_id'))) {
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
