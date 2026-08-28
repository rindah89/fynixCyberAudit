<?php

namespace App\Suite;

use App\Models\GovernanceControlResult;
use App\Models\GovernanceException;
use App\Models\GovernanceStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GovernanceEvidenceService
{
    public function __construct(private readonly DataGovernanceControlService $controls) {}

    /** @param array<string, mixed> $envelope */
    public function record(array $envelope, string $source, string $deliveryId, string $raw): GovernanceStatement
    {
        $payload = $envelope['payload'];
        $this->assertCompleteControlSet($payload['controls']);

        return DB::transaction(function () use ($envelope, $payload, $source, $deliveryId, $raw): GovernanceStatement {
            $statement = GovernanceStatement::query()->create([
                'statement_id' => $envelope['entity_id'],
                'delivery_id' => $deliveryId,
                'source' => $source,
                'tenant_id' => $envelope['tenant_id'],
                'schema_version' => $payload['schema_version'],
                'period_start' => CarbonImmutable::parse($payload['period_start'])->utc(),
                'period_end' => CarbonImmutable::parse($payload['period_end'])->utc(),
                'occurred_at' => CarbonImmutable::parse($envelope['occurred_at'])->utc(),
                'payload_sha256' => hash('sha256', $raw),
            ]);

            foreach ($payload['controls'] as $control) {
                $control = $this->evidenceBackedControl($control, $statement->tenant_id, $source, $statement->occurred_at);
                $result = $statement->controlResults()->create([
                    'control_id' => $control['control_id'],
                    'status' => $control['status'],
                    'observed_at' => CarbonImmutable::parse($control['observed_at'])->utc(),
                    'summary' => $control['summary'] ?? null,
                    'evidence_refs' => $control['evidence_refs'],
                    'metrics' => $control['metrics'],
                ]);
                $this->reconcileException($statement, $result);
            }

            return $statement->load('controlResults');
        });
    }

    /** @param array<string, mixed> $control
     * @return array<string, mixed>
     */
    private function evidenceBackedControl(array $control, string $tenantId, string $source, \DateTimeInterface $observedAt): array
    {
        if ($control['status'] === 'not_applicable'
            && config("data_governance.applicability.{$source}.{$control['control_id']}") !== false) {
            $control['status'] = 'partially_effective';
            $control['summary'] = 'CyberAudit has no approved applicability decision allowing this control to be excluded.';
            $control['metrics']['central_applicability_verified'] = false;

            return $control;
        }
        $supported = match ($control['control_id']) {
            'DG-06' => ! in_array($source, config('data_governance.retention_run_required_sources', []), true)
                || $this->controls->hasCurrentRetentionRun(
                    $tenantId,
                    $source,
                    $observedAt,
                    (string) ($control['metrics']['schedule_sha256'] ?? ''),
                    (int) ($control['metrics']['executable_tables'] ?? 0),
                ),
            'DG-09' => $this->controls->hasCurrentRecoveryEvidence($tenantId, $source, $observedAt),
            'DG-11' => $this->controls->hasCurrentProcessorRegister($tenantId, $source, $observedAt),
            default => true,
        };
        if ($supported && $control['control_id'] === 'DG-11' && $control['status'] === 'partially_effective') {
            $control['status'] = 'effective';
            $control['summary'] = 'CyberAudit independently verified and certified the complete current processor and transfer register.';
            $control['metrics']['central_evidence_verified'] = true;

            return $control;
        }
        if ($supported || ! in_array($control['status'], ['effective', 'not_applicable'], true)) {
            return $control;
        }
        $control['status'] = 'partially_effective';
        $control['summary'] = match ($control['control_id']) {
            'DG-06' => 'CyberAudit has no current independently approved complete retention-run evidence matching this schedule.',
            'DG-09' => 'CyberAudit has no current successful restore-drill evidence for this application.',
            default => 'CyberAudit has no current approved processor register for this application.',
        };
        $control['metrics']['central_evidence_verified'] = false;

        return $control;
    }

    /** @param array<int, array<string, mixed>> $controls */
    private function assertCompleteControlSet(array $controls): void
    {
        $expected = array_keys(config('data_governance.controls', []));
        $actual = array_column($controls, 'control_id');
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['payload.controls' => ['Every suite governance control must appear exactly once.']]);
        }
    }

    private function reconcileException(GovernanceStatement $statement, GovernanceControlResult $result): void
    {
        $identity = ['source' => $statement->source, 'tenant_id' => $statement->tenant_id, 'control_id' => $result->control_id];
        $now = $statement->occurred_at;
        if (in_array($result->status, ['effective', 'not_applicable'], true)) {
            GovernanceException::query()->where($identity)->whereIn('status', ['open', 'waived'])->update([
                'status' => 'resolved', 'resolved_at' => $now, 'last_detected_at' => $now,
                'latest_control_result_id' => $result->id,
            ]);

            return;
        }

        $severity = $result->status === 'ineffective' ? 'high' : ($result->status === 'unknown' ? 'medium' : 'low');
        $existing = GovernanceException::query()->where($identity)->first();
        if ($existing?->status === 'waived' && $existing->due_at?->isFuture()) {
            $existing->update(['last_detected_at' => $now, 'latest_control_result_id' => $result->id]);

            return;
        }
        GovernanceException::query()->updateOrCreate($identity, [
            'status' => 'open',
            'severity' => $severity,
            'reason' => $result->summary ?: 'The application reported '.$result->status.'.',
            'first_detected_at' => $existing?->first_detected_at ?? $now,
            'last_detected_at' => $now,
            'resolved_at' => null,
            'latest_control_result_id' => $result->id,
        ]);
    }
}
