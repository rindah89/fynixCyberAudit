<?php

namespace App\ContinuousControlTesting;

use App\Enums\ControlTestFrequency;
use App\Enums\ControlTestMetricType;
use App\Enums\ControlTestOperator;
use App\Enums\ControlTestOutcome;
use App\Models\ControlTestDefinition;
use App\Models\ControlTestExecution;
use App\Models\User;
use App\Services\GovernanceIssueLifecycleManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlTestRunner
{
    public function validateThreshold(string $metricType, string $operator, string $expectedValue): void
    {
        if ($metricType === ControlTestMetricType::Boolean->value) {
            if (! in_array($expectedValue, ['true', 'false'], true)) {
                throw ValidationException::withMessages(['expected_value' => 'Boolean tests require true or false as the expected value.']);
            }
            if (! in_array($operator, [ControlTestOperator::Equals->value, ControlTestOperator::NotEquals->value], true)) {
                throw ValidationException::withMessages(['operator' => 'Boolean tests support only equals or not_equals.']);
            }
        } elseif (! preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/', $expectedValue)) {
            throw ValidationException::withMessages(['expected_value' => 'Numeric values support up to 15 integer digits and 6 decimal places.']);
        }
    }

    public function execute(ControlTestDefinition $definition, User $actor, string $observedValue, ?string $notes = null, ?string $evidenceReference = null, ?Carbon $executedAt = null): ControlTestExecution
    {
        $executedAt ??= now();

        return DB::transaction(function () use ($definition, $actor, $observedValue, $notes, $evidenceReference, $executedAt) {
            $locked = ControlTestDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['control_test_definition_id' => 'Inactive control tests cannot be executed.']);
            }
            if ($locked->frequency === ControlTestFrequency::OneTime && $locked->last_executed_at) {
                throw ValidationException::withMessages(['control_test_definition_id' => 'Completed one-time control tests cannot be executed again.']);
            }

            [$passed, $reason] = $this->evaluate($locked, $observedValue);
            $outcome = $passed ? ControlTestOutcome::Passed : ControlTestOutcome::Failed;
            $execution = $locked->executions()->create([
                'executed_by' => $actor->id,
                'observed_value' => $observedValue,
                'metric_type' => $locked->metric_type->value,
                'operator' => $locked->operator->value,
                'expected_value' => $locked->expected_value,
                'outcome' => $outcome,
                'result_reason' => $reason,
                'notes' => $notes,
                'evidence_reference' => $evidenceReference,
                'executed_at' => $executedAt,
            ]);

            if (! $passed) {
                $issue = $execution->finding()->create([
                    'control_id' => $locked->control_id,
                    'owner_id' => $locked->owner_id,
                    'title' => "Control test failed: {$locked->name}",
                    'description' => $reason.($notes ? "\n\n{$notes}" : ''),
                    'status' => 'open',
                    'detected_at' => $executedAt,
                ]);
                app(GovernanceIssueLifecycleManager::class)->register($issue, $actor);
            }

            $locked->forceFill([
                'last_executed_at' => $executedAt,
                'last_outcome' => $outcome,
                'next_run_at' => $locked->frequency->nextRunAt($executedAt),
            ])->save();

            return $execution->load('finding');
        });
    }

    private function evaluate(ControlTestDefinition $definition, string $observed): array
    {
        $this->validateThreshold($definition->metric_type->value, $definition->operator->value, $definition->expected_value);
        if ($definition->metric_type === ControlTestMetricType::Boolean) {
            if (! in_array($observed, ['true', 'false'], true)) {
                throw ValidationException::withMessages(['observed_value' => 'Boolean tests require true/false values and an equality operator.']);
            }
            $actual = $observed === 'true';
            $expected = $definition->expected_value === 'true';
        } else {
            if (! preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/', $observed)) {
                throw ValidationException::withMessages(['observed_value' => 'Numeric values support up to 15 integer digits and 6 decimal places.']);
            }
            $actual = $observed;
            $expected = $definition->expected_value;
        }

        $comparison = $definition->metric_type === ControlTestMetricType::Numeric
            ? $this->compareDecimals($actual, $expected)
            : ($actual <=> $expected);
        $passed = match ($definition->operator) {
            ControlTestOperator::Equals => $comparison === 0,
            ControlTestOperator::NotEquals => $comparison !== 0,
            ControlTestOperator::GreaterThan => $comparison > 0,
            ControlTestOperator::GreaterThanOrEqual => $comparison >= 0,
            ControlTestOperator::LessThan => $comparison < 0,
            ControlTestOperator::LessThanOrEqual => $comparison <= 0,
        };

        return [$passed, sprintf('Observed %s value %s %s %s expected value %s.', $definition->metric_type->value, $observed, $passed ? 'satisfied' : 'did not satisfy', $definition->operator->value, $definition->expected_value)];
    }

    private function compareDecimals(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $negative = str_starts_with($value, '-');
            $value = ltrim($value, '-');
            [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

            return [$negative, ltrim($integer, '0') ?: '0', rtrim($fraction, '0')];
        };
        [$leftNegative, $leftInteger, $leftFraction] = $normalize($left);
        [$rightNegative, $rightInteger, $rightFraction] = $normalize($right);
        if ($leftInteger === '0' && $leftFraction === '') {
            $leftNegative = false;
        }
        if ($rightInteger === '0' && $rightFraction === '') {
            $rightNegative = false;
        }
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $comparison = strlen($leftInteger) <=> strlen($rightInteger);
        if ($comparison === 0) {
            $comparison = $leftInteger <=> $rightInteger;
        }
        if ($comparison === 0) {
            $length = max(strlen($leftFraction), strlen($rightFraction));
            $comparison = str_pad($leftFraction, $length, '0') <=> str_pad($rightFraction, $length, '0');
        }

        return $leftNegative ? -$comparison : $comparison;
    }
}
