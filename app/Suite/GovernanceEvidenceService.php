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
