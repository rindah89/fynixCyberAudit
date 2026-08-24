<?php

namespace App\Esg;

use App\Enums\EsgGoalStatus;
use App\Enums\EsgKpiDirection;
use App\Enums\EsgKpiStatus;
use App\Enums\EsgMaterialityDecision;
use App\Enums\EsgTopicStatus;
use App\Models\EsgGoal;
use App\Models\EsgKpi;
use App\Models\EsgKpiObservation;
use App\Models\EsgMaterialityAssessment;
use App\Models\EsgMaterialTopic;
use App\Models\EsgMaterialTopicVersion;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EsgPerformanceManager
{
    /** @param array<string, mixed> $data */
    public function createGoal(User $actor, EsgMaterialTopic $topic, array $data): EsgGoal
    {
        Enterprise::assertEnabled('esg_management');

        return DB::transaction(function () use ($actor, $topic, $data): EsgGoal {
            $lockedTopic = EsgMaterialTopic::query()->lockForUpdate()->findOrFail($topic->id);
            abort_unless($actor->can('update', $lockedTopic), 403);
            $data = Validator::make($data, self::goalRules())->validate();
            $this->assertMaterialTopic($lockedTopic);
            $assessment = EsgMaterialityAssessment::query()->where('esg_material_topic_id', $lockedTopic->id)->latest('version')->lockForUpdate()->firstOrFail();
            $version = EsgMaterialTopicVersion::query()->lockForUpdate()->findOrFail($assessment->topic_version_id);
            $this->assertCurrentAssessment($lockedTopic, $assessment, $version);
            $owner = $this->lockOwner($data['owner_id']);
            $goals = EsgGoal::query()->where('esg_material_topic_id', $lockedTopic->id)->lockForUpdate()->get();
            if ($goals->count() >= 100) {
                throw ValidationException::withMessages(['topic' => 'An ESG material topic is limited to 100 goals.']);
            }
            $at = now()->startOfSecond();
            $payload = [
                'esg_material_topic_id' => $lockedTopic->id,
                'code' => $lockedTopic->code.'-G'.str_pad((string) ($goals->count() + 1), 3, '0', STR_PAD_LEFT),
                'title' => $data['title'], 'description' => $data['description'], 'owner_id' => $owner->id,
                'baseline_date' => Carbon::parse($data['baseline_date'])->toDateString(),
                'target_date' => Carbon::parse($data['target_date'])->toDateString(),
                'topic_snapshot' => $version->topic_snapshot,
                'assessment_snapshot' => $this->assessmentSnapshot($assessment),
                'created_by' => $actor->id, 'governed_at' => $at->toIso8601String(),
            ];

            return EsgGoal::query()->create($payload + [
                'status' => EsgGoalStatus::Active,
                'fingerprint' => $this->fingerprint($payload),
            ])->load(['topic', 'owner:id,name,email', 'creator:id,name']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function defineKpi(User $actor, EsgGoal $goal, array $data): EsgKpi
    {
        Enterprise::assertEnabled('esg_management');
        $topicId = EsgGoal::query()->whereKey($goal->id)->value('esg_material_topic_id');

        return DB::transaction(function () use ($actor, $goal, $data, $topicId): EsgKpi {
            $topic = EsgMaterialTopic::query()->lockForUpdate()->findOrFail($topicId);
            $lockedGoal = EsgGoal::query()->where('esg_material_topic_id', $topic->id)->lockForUpdate()->findOrFail($goal->id);
            abort_unless($actor->can('update', $topic), 403);
            $data = Validator::make($data, self::kpiRules())->validate();
            $this->assertMaterialTopic($topic);
            $latestAssessment = EsgMaterialityAssessment::query()->where('esg_material_topic_id', $topic->id)->latest('version')->lockForUpdate()->firstOrFail();
            if (data_get($lockedGoal->assessment_snapshot, 'fingerprint') !== $latestAssessment->fingerprint) {
                throw ValidationException::withMessages(['goal' => 'New KPIs require a goal established against the latest materiality decision.']);
            }
            if ($lockedGoal->status === EsgGoalStatus::Retired) {
                throw ValidationException::withMessages(['goal' => 'Retired ESG goals cannot receive KPIs.']);
            }
            $owner = $this->lockOwner($data['owner_id']);
            $direction = EsgKpiDirection::from($data['direction']);
            $this->assertDecimal('baseline_value', $data['baseline_value']);
            $this->assertDecimal('target_value', $data['target_value']);
            $comparison = $this->compare($data['target_value'], $data['baseline_value']);
            if (($direction === EsgKpiDirection::Increase && $comparison <= 0) || ($direction === EsgKpiDirection::Decrease && $comparison >= 0)) {
                throw ValidationException::withMessages(['target_value' => 'The target must move beyond the baseline in the selected direction.']);
            }
            $kpis = EsgKpi::query()->where('esg_goal_id', $lockedGoal->id)->lockForUpdate()->get();
            if ($kpis->count() >= 100) {
                throw ValidationException::withMessages(['goal' => 'An ESG goal is limited to 100 KPIs.']);
            }
            $at = now()->startOfSecond();
            $nextDue = min($at->copy()->addDays((int) $data['frequency_days']), $lockedGoal->target_date->endOfDay());
            $baselineValue = $this->normalizeDecimal($data['baseline_value']);
            $targetValue = $this->normalizeDecimal($data['target_value']);
            $payload = [
                'esg_goal_id' => $lockedGoal->id,
                'code' => $lockedGoal->code.'-K'.str_pad((string) ($kpis->count() + 1), 3, '0', STR_PAD_LEFT),
                'name' => $data['name'], 'description' => $data['description'], 'owner_id' => $owner->id,
                'unit' => $data['unit'], 'direction' => $direction->value,
                'baseline_value' => $baselineValue, 'target_value' => $targetValue,
                'measurement_method' => $data['measurement_method'], 'source_reference' => $data['source_reference'] ?? null,
                'frequency_days' => $data['frequency_days'], 'goal_snapshot' => $this->goalSnapshot($lockedGoal),
                'created_by' => $actor->id, 'governed_at' => $at->toIso8601String(),
            ];

            return EsgKpi::query()->create($payload + [
                'next_due_at' => $nextDue, 'is_active' => true, 'fingerprint' => $this->fingerprint($payload),
            ])->load(['goal.topic', 'owner:id,name,email', 'creator:id,name']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function observe(User $actor, EsgKpi $kpi, array $data): EsgKpiObservation
    {
        Enterprise::assertEnabled('esg_management');
        $ids = EsgKpi::query()->join('esg_goals', 'esg_goals.id', '=', 'esg_kpis.esg_goal_id')->where('esg_kpis.id', $kpi->id)->firstOrFail(['esg_kpis.esg_goal_id', 'esg_goals.esg_material_topic_id']);

        return DB::transaction(function () use ($actor, $kpi, $data, $ids): EsgKpiObservation {
            $topic = EsgMaterialTopic::query()->lockForUpdate()->findOrFail($ids->esg_material_topic_id);
            $goal = EsgGoal::query()->where('esg_material_topic_id', $topic->id)->lockForUpdate()->findOrFail($ids->esg_goal_id);
            $locked = EsgKpi::query()->where('esg_goal_id', $goal->id)->lockForUpdate()->findOrFail($kpi->id);
            abort_unless($actor->can('update', $topic) || ($actor->can('Own ESG Topics') && in_array($actor->id, [$topic->owner_id, $goal->owner_id, $locked->owner_id], true)), 403);
            $data = Validator::make($data, self::observationRules())->validate();
            if (! $locked->is_active || $goal->status === EsgGoalStatus::Retired || $topic->status === EsgTopicStatus::Retired) {
                throw ValidationException::withMessages(['kpi' => 'Inactive or retired ESG governance cannot receive observations.']);
            }
            $observedAt = isset($data['observed_at']) ? Carbon::parse($data['observed_at'])->startOfSecond() : now()->startOfSecond();
            if ($observedAt->isFuture()) {
                throw ValidationException::withMessages(['observed_at' => 'Observation time cannot be in the future.']);
            }
            $this->assertDecimal('observed_value', $data['observed_value']);
            $observedValue = $this->normalizeDecimal($data['observed_value']);
            $history = EsgKpiObservation::query()->where('esg_kpi_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($history->count() >= 1000) {
                throw ValidationException::withMessages(['kpi' => 'An ESG KPI is limited to 1,000 observations.']);
            }
            $comparison = $this->compare($observedValue, $locked->target_value);
            $met = $locked->direction === EsgKpiDirection::Increase ? $comparison >= 0 : $comparison <= 0;
            $status = $met ? EsgKpiStatus::TargetMet : EsgKpiStatus::TargetNotMet;
            $payload = [
                'esg_kpi_id' => $locked->id, 'version' => $history->count() + 1,
                'kpi_snapshot' => $this->kpiSnapshot($locked, $goal), 'observed_value' => $observedValue,
                'status' => $status->value,
                'reason' => "Observed {$observedValue} {$locked->unit}; target {$locked->target_value}; direction {$locked->direction->value}; derived status {$status->value}.",
                'notes' => $data['notes'] ?? null, 'source_reference' => $data['source_reference'] ?? null,
                'observed_by' => $actor->id, 'observed_at' => $observedAt->toIso8601String(),
            ];
            $observation = EsgKpiObservation::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            if (! $locked->last_observed_at || $observedAt->greaterThanOrEqualTo($locked->last_observed_at)) {
                $locked->update([
                    'last_observed_at' => $observedAt, 'last_status' => $status,
                    'next_due_at' => min($observedAt->copy()->addDays($locked->frequency_days), $goal->target_date->endOfDay()),
                ]);
            }
            $statuses = EsgKpi::query()->where('esg_goal_id', $goal->id)->where('is_active', true)->pluck('last_status');
            $allTargetsMet = $statuses->isNotEmpty() && $statuses->every(
                fn (mixed $value): bool => ($value instanceof EsgKpiStatus ? $value : EsgKpiStatus::tryFrom((string) $value)) === EsgKpiStatus::TargetMet,
            );
            $goal->update(['status' => $allTargetsMet ? EsgGoalStatus::Achieved : EsgGoalStatus::AtRisk]);

            return $observation->load(['observer:id,name', 'kpi.owner:id,name']);
        }, 3);
    }

    public static function goalRules(): array
    {
        return ['title' => 'required|string|max:255', 'description' => 'required|string|max:30000', 'owner_id' => 'required|integer|exists:users,id', 'baseline_date' => 'required|date|before_or_equal:today', 'target_date' => 'required|date|after:today', 'code' => 'prohibited', 'status' => 'prohibited', 'topic_snapshot' => 'prohibited', 'assessment_snapshot' => 'prohibited', 'created_by' => 'prohibited', 'governed_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function kpiRules(): array
    {
        return ['name' => 'required|string|max:255', 'description' => 'required|string|max:30000', 'owner_id' => 'required|integer|exists:users,id', 'unit' => 'required|string|max:100', 'direction' => ['required', Rule::enum(EsgKpiDirection::class)], 'baseline_value' => 'required|string', 'target_value' => 'required|string', 'measurement_method' => 'required|string|max:30000', 'source_reference' => 'nullable|string|max:2000', 'frequency_days' => 'required|integer|min:1|max:365', 'code' => 'prohibited', 'next_due_at' => 'prohibited', 'last_status' => 'prohibited', 'goal_snapshot' => 'prohibited', 'created_by' => 'prohibited', 'governed_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function observationRules(): array
    {
        return ['observed_value' => 'required|string', 'observed_at' => 'nullable|date', 'notes' => 'nullable|string|max:30000', 'source_reference' => 'nullable|string|max:2000', 'version' => 'prohibited', 'kpi_snapshot' => 'prohibited', 'status' => 'prohibited', 'reason' => 'prohibited', 'observed_by' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function assertMaterialTopic(EsgMaterialTopic $topic): void
    {
        if ($topic->status !== EsgTopicStatus::Material) {
            throw ValidationException::withMessages(['topic' => 'ESG goals require a currently material topic.']);
        }
    }

    private function assertCurrentAssessment(EsgMaterialTopic $topic, EsgMaterialityAssessment $assessment, EsgMaterialTopicVersion $version): void
    {
        $current = $this->topicSnapshot($topic);
        if ($assessment->decision !== EsgMaterialityDecision::Material || $assessment->topic_version_id !== $version->id || Arr::except($version->topic_snapshot, ['status', 'next_review_at']) !== Arr::except($current, ['status', 'next_review_at'])) {
            throw ValidationException::withMessages(['topic' => 'ESG goals require the latest exact material assessment.']);
        }
    }

    private function lockOwner(int $id): User
    {
        $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->find($id);
        if (! $owner || (! $owner->can('Own ESG Topics') && ! $owner->can('Manage ESG'))) {
            throw ValidationException::withMessages(['owner_id' => 'The ESG owner must be active and authorized.']);
        }

        return $owner;
    }

    private function topicSnapshot(EsgMaterialTopic $topic): array
    {
        $topic->load('owner:id,name,email');
        $snapshot = $topic->only(['id', 'code', 'name', 'pillar', 'status', 'description', 'impact_context', 'risk_context', 'opportunity_context', 'stakeholder_groups', 'framework_references', 'organizational_boundary', 'source_reference', 'next_review_at', 'governed_at']) + ['owner' => $topic->owner?->only(['id', 'name', 'email'])];

        return $this->canonical($snapshot);
    }

    private function assessmentSnapshot(EsgMaterialityAssessment $assessment): array
    {
        return $this->canonical($assessment->only(['id', 'version', 'topic_version_id', 'topic_snapshot', 'impact_materiality', 'financial_materiality', 'stakeholder_evidence', 'methodology', 'decision', 'decision_summary', 'assessed_by', 'assessed_at', 'next_review_at', 'fingerprint']));
    }

    private function goalSnapshot(EsgGoal $goal): array
    {
        $goal->load(['owner:id,name,email', 'creator:id,name,email']);

        return $this->canonical($goal->only(['id', 'esg_material_topic_id', 'code', 'title', 'description', 'status', 'baseline_date', 'target_date', 'topic_snapshot', 'assessment_snapshot', 'governed_at', 'fingerprint']) + ['owner' => $goal->owner?->only(['id', 'name', 'email']), 'creator' => $goal->creator?->only(['id', 'name', 'email'])]);
    }

    private function kpiSnapshot(EsgKpi $kpi, EsgGoal $goal): array
    {
        $kpi->load(['owner:id,name,email', 'creator:id,name,email']);

        return $this->canonical($kpi->only(['id', 'esg_goal_id', 'code', 'name', 'description', 'unit', 'direction', 'baseline_value', 'target_value', 'measurement_method', 'source_reference', 'frequency_days', 'is_active', 'governed_at', 'fingerprint']) + ['owner' => $kpi->owner?->only(['id', 'name', 'email']), 'creator' => $kpi->creator?->only(['id', 'name', 'email']), 'goal' => $this->goalSnapshot($goal)]);
    }

    private function assertDecimal(string $field, mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Numeric values support up to 14 integer digits and 6 decimal places.']);
        }
    }

    private function compare(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $negative = str_starts_with($value, '-');
            [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
            $integer = ltrim($integer, '0') ?: '0';
            $fraction = rtrim($fraction, '0');
            if ($integer === '0' && $fraction === '') {
                $negative = false;
            }

            return [$negative, $integer, $fraction];
        };
        [$leftNegative, $leftInteger, $leftFraction] = $normalize($left);
        [$rightNegative, $rightInteger, $rightFraction] = $normalize($right);
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $result = strlen($leftInteger) <=> strlen($rightInteger);
        $result = $result ?: ($leftInteger <=> $rightInteger);
        if ($result === 0) {
            $length = max(strlen($leftFraction), strlen($rightFraction));
            $result = str_pad($leftFraction, $length, '0') <=> str_pad($rightFraction, $length, '0');
        }

        return $leftNegative ? -$result : $result;
    }

    private function normalizeDecimal(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = str_pad($fraction, 6, '0');
        $prefix = $negative && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '';

        return $prefix.$integer.'.'.$fraction;
    }

    private function canonical(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
