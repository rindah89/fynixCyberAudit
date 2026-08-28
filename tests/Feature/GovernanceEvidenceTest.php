<?php

namespace Tests\Feature;

use App\Models\GovernanceException;
use App\Models\GovernanceStatement;
use App\Models\User;
use App\Suite\DataGovernanceControlService;
use App\Suite\SuiteEnvelope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GovernanceEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'finance';

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const WEBHOOK = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('data_governance.required_sources', [self::SOURCE]);
        Config::set('data_governance.bindings.'.self::SOURCE, [
            'enabled' => true,
            'tenant_id' => self::TENANT,
            'webhook_id' => self::WEBHOOK,
            'secret' => 'governance-secret',
            'replay_tolerance' => 300,
        ]);
    }

    public function test_unsigned_statement_is_rejected(): void
    {
        $this->postSigned($this->statement(), signature: '')
            ->assertUnauthorized()
            ->assertJsonPath('outcome', 'invalid signature');
    }

    public function test_tenant_mismatch_is_rejected_without_persistence(): void
    {
        $statement = $this->statement(['tenant_id' => 'different-tenant']);
        $this->postSigned($statement)->assertForbidden()->assertJsonPath('outcome', 'tenant mismatch');
        $this->assertDatabaseCount('governance_statements', 0);
    }

    public function test_oversized_payload_wrong_webhook_and_stale_signature_are_rejected(): void
    {
        $this->call('POST', '/api/suite/governance/evidence', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], str_repeat('x', 65537))->assertStatus(413);

        $this->postSigned($this->statement(), webhookId: (string) Str::uuid())
            ->assertServiceUnavailable()
            ->assertJsonPath('outcome', 'binding disabled');

        $this->postSigned($this->statement(), timestamp: time() - 301)
            ->assertUnauthorized()
            ->assertJsonPath('outcome', 'invalid signature');

        $this->assertDatabaseCount('governance_statements', 0);
    }

    public function test_complete_statement_is_recorded_and_duplicate_delivery_is_idempotent(): void
    {
        $statement = $this->statement();
        $delivery = (string) Str::uuid();
        $this->postSigned($statement, $delivery)->assertCreated()->assertJsonPath('outcome', 'recorded');
        $this->postSigned($statement, $delivery)->assertOk()->assertJsonPath('outcome', 'duplicate ignored');

        $this->assertDatabaseCount('governance_statements', 1);
        $this->assertDatabaseCount('governance_control_results', 12);
        $this->assertSame(64, strlen((string) GovernanceStatement::query()->firstOrFail()->payload_sha256));
    }

    public function test_backup_and_processor_claims_are_downgraded_without_central_evidence(): void
    {
        $this->postSigned($this->statement())->assertCreated();
        $results = GovernanceStatement::query()->firstOrFail()->controlResults()->whereIn('control_id', ['DG-09', 'DG-11'])->get();
        $this->assertSame(['partially_effective'], $results->pluck('status')->unique()->values()->all());
        $this->assertDatabaseHas('governance_exceptions', ['source' => self::SOURCE, 'control_id' => 'DG-09', 'status' => 'open']);
        $this->assertDatabaseHas('governance_exceptions', ['source' => self::SOURCE, 'control_id' => 'DG-11', 'status' => 'open']);
    }

    public function test_reused_statement_id_with_a_new_delivery_is_rejected_as_a_conflict(): void
    {
        $statement = $this->statement();
        $this->postSigned($statement)->assertCreated();
        $this->postSigned($statement)->assertConflict()->assertJsonPath('outcome', 'statement conflict');
        $this->assertDatabaseCount('governance_statements', 1);
    }

    public function test_incomplete_or_duplicate_control_set_is_rejected(): void
    {
        $statement = $this->statement();
        array_pop($statement['payload']['controls']);
        $statement['payload']['controls'][] = $statement['payload']['controls'][0];

        $this->postSigned($statement)->assertUnprocessable()->assertJsonPath('outcome', 'invalid statement');
        $this->assertDatabaseCount('governance_statements', 0);
    }

    public function test_failing_result_opens_exception_and_later_effective_result_resolves_it(): void
    {
        $statement = $this->statement();
        $statement['payload']['controls'][4]['status'] = 'ineffective';
        $statement['payload']['controls'][4]['summary'] = 'Denied reads are not audited.';
        $this->postSigned($statement)->assertCreated();

        $exception = GovernanceException::query()->where('control_id', 'DG-05')->firstOrFail();
        $this->assertSame('open', $exception->status);
        $this->assertSame('high', $exception->severity);

        $next = $this->statement([
            'entity_id' => (string) Str::uuid(),
            'occurred_at' => now()->addMinute()->utc()->toIso8601String(),
        ]);
        $this->postSigned($next)->assertCreated();
        $this->assertSame('resolved', $exception->fresh()->status);
        $this->assertNotNull($exception->fresh()->resolved_at);
    }

    public function test_waiver_requires_active_expiry_and_is_reopened_after_expiry(): void
    {
        $controls = app(DataGovernanceControlService::class);
        $controls->recordRecoveryEvidence([
            'tenant_id' => self::TENANT, 'source' => self::SOURCE, 'kind' => 'restore_drill',
            'occurred_at' => now(), 'outcome' => 'successful', 'evidence_ref' => 'evidence://restore/finance/waiver-test', 'evidence_sha256' => str_repeat('a', 64),
        ]);
        $controls->registerProcessor([
            'tenant_id' => self::TENANT, 'source' => self::SOURCE, 'name' => 'Waiver test hosting',
            'purpose' => 'Application hosting', 'data_categories' => ['financial_records'],
            'processing_countries' => [], 'agreement_owner' => 'privacy', 'agreement_evidence_ref' => 'evidence://finance/dpa/waiver-test', 'agreement_evidence_sha256' => str_repeat('b', 64), 'review_due_at' => now()->addYear(),
        ]);
        $statement = $this->statement();
        $statement['payload']['controls'][4]['status'] = 'ineffective';
        $this->postSigned($statement)->assertCreated();

        $exception = GovernanceException::query()->where('control_id', 'DG-05')->firstOrFail();
        $exception->update([
            'status' => 'waived',
            'owner' => 'Chief Data Officer',
            'due_at' => now()->addDay(),
            'resolution_notes' => 'Time-bound risk acceptance while audit logging is remediated.',
        ]);

        $stillFailing = $this->statement(['entity_id' => (string) Str::uuid()]);
        $stillFailing['payload']['controls'][4]['status'] = 'ineffective';
        $this->postSigned($stillFailing)->assertCreated();
        $this->assertSame('waived', $exception->fresh()->status);
        $this->getJson('/api/suite/governance/ready')
            ->assertOk()
            ->assertJsonPath('status', 'attention_required')
            ->assertJsonPath('sources.finance.waived_exceptions', 1);

        $exception->update(['due_at' => now()->subMinute()]);
        $afterExpiry = $this->statement(['entity_id' => (string) Str::uuid()]);
        $afterExpiry['payload']['controls'][4]['status'] = 'ineffective';
        $this->postSigned($afterExpiry)->assertCreated();
        $this->assertSame('open', $exception->fresh()->status);
    }

    public function test_readiness_and_authenticated_oversight_report_real_coverage(): void
    {
        $this->getJson('/api/suite/governance/ready')
            ->assertOk()
            ->assertJsonPath('status', 'attention_required')
            ->assertJsonPath('sources.finance.freshness', 'missing');

        $controls = app(DataGovernanceControlService::class);
        $controls->recordRecoveryEvidence([
            'tenant_id' => self::TENANT, 'source' => self::SOURCE, 'kind' => 'restore_drill',
            'occurred_at' => now(), 'outcome' => 'successful', 'evidence_ref' => 'evidence://restore/finance/current', 'evidence_sha256' => str_repeat('c', 64),
        ]);
        $controls->registerProcessor([
            'tenant_id' => self::TENANT, 'source' => self::SOURCE, 'name' => 'Finance hosting',
            'purpose' => 'Application hosting', 'data_categories' => ['financial_records'],
            'processing_countries' => [], 'agreement_owner' => 'privacy', 'agreement_evidence_ref' => 'evidence://finance/dpa/hosting', 'agreement_evidence_sha256' => str_repeat('d', 64), 'review_due_at' => now()->addYear(),
        ]);
        $this->postSigned($this->statement())->assertCreated();
        $this->getJson('/api/suite/governance/ready')
            ->assertOk()
            ->assertJsonPath('status', 'attention_required')
            ->assertJsonPath('sources.finance.effective_controls', 10)
            ->assertJsonPath('sources.finance.operability.pending_processor_reviews', 1)
            ->assertJsonPath('sources.finance.operability.overdue_privacy_requests', 0);

        $auditor = User::factory()->create();
        $auditor->assignRole('Internal Auditor');
        Sanctum::actingAs($auditor);
        $this->getJson('/api/governance/oversight')
            ->assertOk()
            ->assertJsonCount(12, 'controls')
            ->assertJsonCount(2, 'open_exceptions');
    }

    public function test_oversight_requires_explicit_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/governance/oversight')->assertForbidden();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function statement(array $overrides = []): array
    {
        $now = now()->utc();
        $controls = [];
        foreach (array_keys(config('data_governance.controls')) as $controlId) {
            $controls[] = [
                'control_id' => $controlId,
                'status' => 'effective',
                'observed_at' => $now->toIso8601String(),
                'summary' => 'Automated control check passed.',
                'evidence_refs' => ['urn:fynix:test:'.$controlId],
                'metrics' => ['checks' => 1],
            ];
        }

        return array_replace([
            'event_type' => 'governance.evidence.reported',
            'tenant_id' => self::TENANT,
            'entity_type' => 'governance_statement',
            'entity_id' => (string) Str::uuid(),
            'occurred_at' => $now->toIso8601String(),
            'payload' => [
                'schema_version' => 'fynix-governance-evidence/v1',
                'period_start' => $now->subDay()->toIso8601String(),
                'period_end' => $now->toIso8601String(),
                'controls' => $controls,
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $statement */
    private function postSigned(
        array $statement,
        ?string $delivery = null,
        ?string $signature = null,
        ?int $timestamp = null,
        string $webhookId = self::WEBHOOK,
    ) {
        $raw = (string) json_encode($statement, JSON_UNESCAPED_SLASHES);
        $timestamp ??= time();
        $delivery ??= (string) Str::uuid();
        $signature ??= SuiteEnvelope::sign('governance-secret', $timestamp, 'governance.evidence.reported', self::SOURCE, $webhookId, $delivery, $raw);

        return $this->call('POST', '/api/suite/governance/evidence', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FYNIX_SIGNATURE' => $signature,
            'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => 'governance.evidence.reported',
            'HTTP_X_FYNIX_SOURCE' => self::SOURCE,
            'HTTP_X_FYNIX_WEBHOOK_ID' => $webhookId,
            'HTTP_X_FYNIX_DELIVERY_ID' => $delivery,
        ], $raw);
    }
}
