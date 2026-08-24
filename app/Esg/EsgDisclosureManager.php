<?php

namespace App\Esg;

use App\Enums\EsgDataValidationOutcome;
use App\Enums\EsgDisclosureDecision;
use App\Models\EsgDataValidation;
use App\Models\EsgDisclosure;
use App\Models\EsgDisclosureDecisionRecord;
use App\Models\EsgGoal;
use App\Models\EsgKpi;
use App\Models\EsgKpiObservation;
use App\Models\EsgMaterialTopic;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EsgDisclosureManager
{
    /** @param array<string, mixed> $data */
    public function validateData(User $actor, EsgKpiObservation $observation, array $data): EsgDataValidation
    {
        Enterprise::assertEnabled('esg_management');
        abort_unless($actor->can('Validate ESG Data') || $actor->can('Manage ESG'), 403);
        $data = Validator::make($data, self::validationRules())->validate();
        $ids = EsgKpiObservation::query()->join('esg_kpis', 'esg_kpis.id', '=', 'esg_kpi_observations.esg_kpi_id')->join('esg_goals', 'esg_goals.id', '=', 'esg_kpis.esg_goal_id')->where('esg_kpi_observations.id', $observation->id)->firstOrFail(['esg_kpi_observations.esg_kpi_id', 'esg_kpis.esg_goal_id', 'esg_goals.esg_material_topic_id']);

        return DB::transaction(function () use ($actor, $observation, $data, $ids): EsgDataValidation {
            $this->lockMutex();
            $topic = EsgMaterialTopic::query()->lockForUpdate()->findOrFail($ids->esg_material_topic_id);
            $goal = EsgGoal::query()->where('esg_material_topic_id', $topic->id)->lockForUpdate()->findOrFail($ids->esg_goal_id);
            $kpi = EsgKpi::query()->where('esg_goal_id', $goal->id)->lockForUpdate()->findOrFail($ids->esg_kpi_id);
            $lockedObservation = EsgKpiObservation::query()->where('esg_kpi_id', $kpi->id)->lockForUpdate()->findOrFail($observation->id);
            abort_unless($actor->can('Validate ESG Data') || $actor->can('Manage ESG'), 403);
            abort_if(in_array($actor->id, [$lockedObservation->observed_by, $kpi->owner_id, $goal->owner_id, $topic->owner_id], true), 403, 'ESG data validation must be independent from observation and ownership.');
            $history = EsgDataValidation::query()->where('esg_kpi_observation_id', $lockedObservation->id)->orderBy('id')->lockForUpdate()->get();
            if ($history->count() >= 20) {
                throw ValidationException::withMessages(['observation' => 'An ESG observation is limited to 20 validation decisions.']);
            }
            $at = now()->startOfSecond();
            $payload = [
                'esg_kpi_observation_id' => $lockedObservation->id, 'version' => $history->count() + 1,
                'observation_snapshot' => $this->observationSnapshot($lockedObservation),
                'completeness_assessment' => $data['completeness_assessment'],
                'accuracy_assessment' => $data['accuracy_assessment'],
                'consistency_assessment' => $data['consistency_assessment'],
                'evidence_reference' => $data['evidence_reference'] ?? null,
                'outcome' => $data['outcome'], 'summary' => $data['summary'],
                'validated_by' => $actor->id, 'validated_at' => $at->toIso8601String(),
            ];

            return EsgDataValidation::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load(['validator:id,name', 'observation']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function prepare(User $actor, array $data): EsgDisclosure
    {
        Enterprise::assertEnabled('esg_management');
        abort_unless($actor->can('Manage ESG'), 403);
        $data = Validator::make($data, self::disclosureRules())->validate();

        return DB::transaction(function () use ($actor, $data): EsgDisclosure {
            $this->lockMutex();
            abort_unless($actor->can('Manage ESG'), 403);
            $versions = EsgDisclosure::query()->where('disclosure_key', $data['disclosure_key'])->orderBy('id')->lockForUpdate()->get();
            if ($versions->count() >= 100) {
                throw ValidationException::withMessages(['disclosure_key' => 'An ESG disclosure series is limited to 100 versions.']);
            }
            $validations = EsgDataValidation::query()->whereKey($data['validation_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($validations->count() !== count($data['validation_ids'])) {
                throw ValidationException::withMessages(['validation_ids' => 'Every selected ESG data validation must exist.']);
            }
            $this->assertEligibleValidations($validations, Carbon::parse($data['reporting_period_start']), Carbon::parse($data['reporting_period_end']));
            $at = now()->startOfSecond();
            $version = $versions->count() + 1;
            $payload = [
                'disclosure_key' => $data['disclosure_key'],
                'code' => $data['disclosure_key'].'-V'.str_pad((string) $version, 3, '0', STR_PAD_LEFT),
                'version' => $version, 'title' => $data['title'],
                'reporting_period_start' => Carbon::parse($data['reporting_period_start'])->toDateString(),
                'reporting_period_end' => Carbon::parse($data['reporting_period_end'])->toDateString(),
                'framework_references' => array_values($data['framework_references']),
                'narrative' => $data['narrative'],
                'validation_snapshot' => $validations->map(fn (EsgDataValidation $validation): array => $this->validationSnapshot($validation))->values()->all(),
                'prepared_by' => $actor->id, 'prepared_at' => $at->toIso8601String(),
            ];
            $disclosure = EsgDisclosure::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            $disclosure->validations()->attach($validations->modelKeys());

            return $disclosure->load(['preparer:id,name', 'validations.validator:id,name']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function decide(User $actor, EsgDisclosure $disclosure, array $data): EsgDisclosureDecisionRecord
    {
        Enterprise::assertEnabled('esg_management');
        abort_unless($actor->can('Approve ESG Disclosures') || $actor->can('Manage ESG'), 403);
        $data = Validator::make($data, self::decisionRules())->validate();

        return DB::transaction(function () use ($actor, $disclosure, $data): EsgDisclosureDecisionRecord {
            $this->lockMutex();
            $locked = EsgDisclosure::query()->lockForUpdate()->findOrFail($disclosure->id);
            abort_unless($actor->can('Approve ESG Disclosures') || $actor->can('Manage ESG'), 403);
            $latest = EsgDisclosure::query()->where('disclosure_key', $locked->disclosure_key)->latest('version')->lockForUpdate()->firstOrFail();
            if ($latest->id !== $locked->id) {
                throw ValidationException::withMessages(['disclosure' => 'Only the latest ESG disclosure version can be decided.']);
            }
            $decisions = EsgDisclosureDecisionRecord::query()->where('esg_disclosure_id', $locked->id)->lockForUpdate()->get();
            if ($decisions->isNotEmpty()) {
                throw ValidationException::withMessages(['disclosure' => 'This ESG disclosure version already has a terminal decision.']);
            }
            $validations = $this->lockedDisclosureValidations($locked);
            abort_if($actor->id === $locked->prepared_by || $validations->contains('validated_by', $actor->id), 403, 'Disclosure approval must be independent from preparation and selected data validation.');
            $this->assertEligibleValidations($validations, $locked->reporting_period_start, $locked->reporting_period_end);
            $snapshot = $this->disclosureSnapshot($locked, $validations);
            if ($snapshot['validation_snapshot'] !== $locked->validation_snapshot) {
                throw ValidationException::withMessages(['disclosure' => 'The retained disclosure validation context no longer matches current governed validation evidence.']);
            }
            $at = now()->startOfSecond();
            $payload = [
                'esg_disclosure_id' => $locked->id, 'version' => 1,
                'disclosure_snapshot' => $snapshot, 'decision' => $data['decision'],
                'rationale' => $data['rationale'], 'decided_by' => $actor->id,
                'decided_at' => $at->toIso8601String(),
            ];

            return EsgDisclosureDecisionRecord::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('decider:id,name');
        }, 3);
    }

    public static function validationRules(): array
    {
        return ['completeness_assessment' => 'required|string|max:30000', 'accuracy_assessment' => 'required|string|max:30000', 'consistency_assessment' => 'required|string|max:30000', 'evidence_reference' => 'nullable|string|max:2000', 'outcome' => ['required', Rule::enum(EsgDataValidationOutcome::class)], 'summary' => 'required|string|max:30000', 'version' => 'prohibited', 'observation_snapshot' => 'prohibited', 'validated_by' => 'prohibited', 'validated_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function disclosureRules(): array
    {
        return ['disclosure_key' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9_-]{2,99}$/'], 'title' => 'required|string|max:255', 'reporting_period_start' => 'required|date|before_or_equal:reporting_period_end', 'reporting_period_end' => 'required|date|before_or_equal:today', 'framework_references' => 'required|array|min:1|max:100', 'framework_references.*' => 'required|string|max:255|distinct', 'narrative' => 'required|string|max:30000', 'validation_ids' => 'required|array|min:1|max:100', 'validation_ids.*' => 'integer|distinct|exists:esg_data_validations,id', 'code' => 'prohibited', 'version' => 'prohibited', 'validation_snapshot' => 'prohibited', 'prepared_by' => 'prohibited', 'prepared_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function decisionRules(): array
    {
        return ['decision' => ['required', Rule::enum(EsgDisclosureDecision::class)], 'rationale' => 'required|string|max:30000', 'version' => 'prohibited', 'disclosure_snapshot' => 'prohibited', 'decided_by' => 'prohibited', 'decided_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function assertEligibleValidations(Collection $validations, Carbon $periodStart, Carbon $periodEnd): void
    {
        $observationIds = [];
        foreach ($validations as $validation) {
            $latest = EsgDataValidation::query()->where('esg_kpi_observation_id', $validation->esg_kpi_observation_id)->latest('version')->lockForUpdate()->firstOrFail();
            $observedAt = Carbon::parse(data_get($validation->observation_snapshot, 'observed_at'));
            if ($latest->id !== $validation->id || $validation->outcome !== EsgDataValidationOutcome::Validated || $observedAt->lt($periodStart->startOfDay()) || $observedAt->gt($periodEnd->endOfDay()) || in_array($validation->esg_kpi_observation_id, $observationIds, true)) {
                throw ValidationException::withMessages(['validation_ids' => 'Disclosures require one latest validated decision per observation within the reporting period.']);
            }
            $observationIds[] = $validation->esg_kpi_observation_id;
        }
    }

    private function lockedDisclosureValidations(EsgDisclosure $disclosure): Collection
    {
        $ids = DB::table('esg_disclosure_validation')->where('esg_disclosure_id', $disclosure->id)->orderBy('esg_data_validation_id')->lockForUpdate()->pluck('esg_data_validation_id');

        return EsgDataValidation::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function observationSnapshot(EsgKpiObservation $observation): array
    {
        $observation->load('observer:id,name,email');

        return $this->canonical($observation->only(['id', 'esg_kpi_id', 'version', 'kpi_snapshot', 'observed_value', 'status', 'reason', 'notes', 'source_reference', 'observed_at', 'fingerprint']) + ['observer' => $observation->observer?->only(['id', 'name', 'email'])]);
    }

    private function validationSnapshot(EsgDataValidation $validation): array
    {
        $validation->load('validator:id,name,email');

        return $this->canonical($validation->only(['id', 'esg_kpi_observation_id', 'version', 'observation_snapshot', 'completeness_assessment', 'accuracy_assessment', 'consistency_assessment', 'evidence_reference', 'outcome', 'summary', 'validated_at', 'fingerprint']) + ['validator' => $validation->validator?->only(['id', 'name', 'email'])]);
    }

    private function disclosureSnapshot(EsgDisclosure $disclosure, Collection $validations): array
    {
        $disclosure->load('preparer:id,name,email');

        return $this->canonical($disclosure->only(['id', 'disclosure_key', 'code', 'version', 'title', 'reporting_period_start', 'reporting_period_end', 'framework_references', 'narrative', 'prepared_at', 'fingerprint']) + ['validation_snapshot' => $validations->map(fn (EsgDataValidation $validation): array => $this->validationSnapshot($validation))->values()->all(), 'preparer' => $disclosure->preparer?->only(['id', 'name', 'email'])]);
    }

    private function lockMutex(): void
    {
        DB::table('esg_material_topic_mutexes')->where('id', 1)->lockForUpdate()->first();
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
