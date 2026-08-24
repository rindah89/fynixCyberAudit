<?php

namespace App\Privacy;

use App\Enums\PrivacyActivityStatus;
use App\Enums\PrivacyAssessmentDecision;
use App\Models\PrivacyActivityVersion;
use App\Models\PrivacyImpactAssessment;
use App\Models\PrivacyProcessingActivity;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrivacyManagementManager
{
    /** @param array<string,mixed> $data */
    public function register(User $actor, array $data): PrivacyProcessingActivity
    {
        Enterprise::assertEnabled('privacy_management');
        abort_unless($actor->can('create', PrivacyProcessingActivity::class), 403);
        $data = Validator::make($data, self::activityRules(true))->validate();

        return DB::transaction(function () use ($actor, $data): PrivacyProcessingActivity {
            DB::table('privacy_activity_mutexes')->where('id', 1)->lockForUpdate()->first();
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['owner_id']);
            if (! $owner->can('Own Privacy Activities') && ! $owner->can('Manage Privacy')) {
                throw ValidationException::withMessages(['owner_id' => 'The owner must hold Own Privacy Activities or Manage Privacy.']);
            }
            $this->assertTransferContext($data);
            $now = now()->startOfSecond();
            $next = ((int) PrivacyProcessingActivity::query()->max('id')) + 1;
            $activity = PrivacyProcessingActivity::query()->create([
                ...Arr::except($data, ['change_summary']), 'number' => 'PA-'.$now->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'status' => PrivacyActivityStatus::Draft, 'governed_at' => $now,
            ]);
            $this->appendVersion($activity, $actor, $data['change_summary'], 1, $now);

            return $activity->load(['owner:id,name,email', 'versions.actor:id,name']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function revise(User $actor, PrivacyProcessingActivity $activity, array $data): PrivacyActivityVersion
    {
        Enterprise::assertEnabled('privacy_management');

        return DB::transaction(function () use ($actor, $activity, $data): PrivacyActivityVersion {
            $locked = PrivacyProcessingActivity::query()->lockForUpdate()->findOrFail($activity->id);
            abort_unless($actor->can('update', $locked), 403);
            $data = Validator::make($data, self::activityRules(false))->validate();
            $versions = PrivacyActivityVersion::query()->where('privacy_processing_activity_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($versions->count() >= 100) {
                throw ValidationException::withMessages(['activity' => 'A processing activity is limited to 100 versions.']);
            }
            if ($locked->status === PrivacyActivityStatus::Retired) {
                throw ValidationException::withMessages(['activity' => 'Retired processing activities are immutable.']);
            }
            $ownerId = $data['owner_id'] ?? $locked->owner_id;
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($ownerId);
            if (! $owner->can('Own Privacy Activities') && ! $owner->can('Manage Privacy')) {
                throw ValidationException::withMessages(['owner_id' => 'The owner must be active and authorized.']);
            }
            $changes = Arr::except($data, ['change_summary']);
            $this->assertTransferContext(array_merge($locked->getAttributes(), $changes));
            $materialChanges = Arr::except($changes, ['status']);
            $targetStatus = isset($changes['status']) ? PrivacyActivityStatus::from($changes['status']) : $locked->status;
            if ($locked->status === PrivacyActivityStatus::Active && $materialChanges !== []) {
                $changes['status'] = PrivacyActivityStatus::AssessmentRequired;
                $targetStatus = PrivacyActivityStatus::AssessmentRequired;
            }
            if ($targetStatus === PrivacyActivityStatus::Draft && $locked->status !== PrivacyActivityStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'A governed activity cannot return to draft.']);
            }
            if ($targetStatus === PrivacyActivityStatus::Active) {
                $latestAssessment = PrivacyImpactAssessment::query()->where('privacy_processing_activity_id', $locked->id)->latest('version')->lockForUpdate()->first();
                $latestVersion = $versions->last();
                if ($latestAssessment === null || $latestAssessment->decision !== PrivacyAssessmentDecision::Approved || $latestAssessment->activity_version_id !== $latestVersion?->id || $materialChanges !== []) {
                    throw ValidationException::withMessages(['status' => 'Activation requires an approved assessment of the unchanged latest activity version.']);
                }
            }
            $before = $this->snapshot($locked);
            $locked->update($changes);
            if ($before === $this->snapshot($locked->refresh())) {
                throw ValidationException::withMessages(['activity' => 'A revision must change governed state.']);
            }

            return $this->appendVersion($locked, $actor, $data['change_summary'], $versions->count() + 1, now()->startOfSecond())->load('actor:id,name');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function assess(User $actor, PrivacyProcessingActivity $activity, array $data): PrivacyImpactAssessment
    {
        Enterprise::assertEnabled('privacy_management');

        return DB::transaction(function () use ($actor, $activity, $data): PrivacyImpactAssessment {
            $locked = PrivacyProcessingActivity::query()->lockForUpdate()->findOrFail($activity->id);
            abort_unless($actor->can('assess', $locked), 403);
            $data = Validator::make($data, self::assessmentRules())->validate();
            if ($locked->status === PrivacyActivityStatus::Retired) {
                throw ValidationException::withMessages(['activity' => 'Retired processing activities are terminal.']);
            }
            $version = PrivacyActivityVersion::query()->where('privacy_processing_activity_id', $locked->id)->latest('version')->lockForUpdate()->firstOrFail();
            $assessments = PrivacyImpactAssessment::query()->where('privacy_processing_activity_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($assessments->count() >= 100) {
                throw ValidationException::withMessages(['assessment' => 'A processing activity is limited to 100 assessments.']);
            }
            abort_if($actor->id === $locked->owner_id || $actor->id === $version->recorded_by, 403, 'The owner and latest activity-version author cannot assess that version.');
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->find($locked->owner_id);
            if ($owner === null || (! $owner->can('Own Privacy Activities') && ! $owner->can('Manage Privacy'))) {
                throw ValidationException::withMessages(['activity' => 'The processing activity must retain an active authorized owner.']);
            }
            if ($version->activity_snapshot !== $this->snapshot($locked)) {
                throw ValidationException::withMessages(['activity' => 'The latest retained activity version does not match current state.']);
            }
            $assessedAt = now()->startOfSecond();
            $payload = [
                'privacy_processing_activity_id' => $locked->id, 'version' => $assessments->count() + 1,
                'activity_version_id' => $version->id, 'activity_snapshot' => $version->activity_snapshot,
                'necessity_assessment' => $data['necessity_assessment'], 'proportionality_assessment' => $data['proportionality_assessment'],
                'risk_summary' => $data['risk_summary'], 'mitigations' => $data['mitigations'], 'residual_risk' => $data['residual_risk'],
                'decision' => $data['decision'], 'decision_summary' => $data['decision_summary'], 'next_review_at' => $data['next_review_at'],
                'assessed_by' => $actor->id, 'assessed_at' => $assessedAt->toIso8601String(),
            ];
            $payload['decision'] = PrivacyAssessmentDecision::from($payload['decision'])->value;
            $payload['next_review_at'] = Carbon::parse($payload['next_review_at'])->toDateString();

            return $locked->assessments()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load(['assessor:id,name', 'activityVersion']);
        }, 3);
    }

    /** @return array<string,mixed> */
    public static function activityRules(bool $creating): array
    {
        $rules = [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:255', 'owner_id' => ($creating ? 'required' : 'sometimes').'|integer|exists:users,id',
            'purpose' => ($creating ? 'required' : 'sometimes').'|string|max:30000', 'lawful_basis' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'data_subject_categories' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:100', 'data_subject_categories.*' => 'string|max:255|distinct',
            'personal_data_categories' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:100', 'personal_data_categories.*' => 'string|max:255|distinct',
            'special_category_data' => ($creating ? 'required' : 'sometimes').'|boolean', 'recipient_categories' => ($creating ? 'required' : 'sometimes').'|array|max:100', 'recipient_categories.*' => 'string|max:255|distinct',
            'systems_and_vendors' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:100', 'systems_and_vendors.*' => 'string|max:255|distinct',
            'processing_locations' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:100', 'processing_locations.*' => 'string|max:255|distinct',
            'cross_border_transfer' => ($creating ? 'required' : 'sometimes').'|boolean', 'transfer_safeguards' => 'sometimes|nullable|string|max:30000',
            'retention_period' => ($creating ? 'required' : 'sometimes').'|string|max:255', 'security_measures' => ($creating ? 'required' : 'sometimes').'|string|max:30000',
            'source_reference' => 'sometimes|nullable|string|max:2000', 'next_review_at' => ($creating ? 'required' : 'sometimes').'|date|after_or_equal:today',
            'change_summary' => 'required|string|max:30000', 'status' => ['sometimes', Rule::enum(PrivacyActivityStatus::class)],
            'number' => 'prohibited', 'governed_at' => 'prohibited', 'fingerprint' => 'prohibited', 'version' => 'prohibited',
        ];
        if ($creating) {
            $rules['status'] = 'prohibited';
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    public static function assessmentRules(): array
    {
        return ['necessity_assessment' => 'required|string|max:30000', 'proportionality_assessment' => 'required|string|max:30000', 'risk_summary' => 'required|string|max:30000',
            'mitigations' => 'required|array|max:100', 'mitigations.*' => 'string|max:2000|distinct', 'residual_risk' => ['required', Rule::in(['Low', 'Medium', 'High', 'Critical'])],
            'decision' => ['required', Rule::enum(PrivacyAssessmentDecision::class)], 'decision_summary' => 'required|string|max:30000', 'next_review_at' => 'required|date|after_or_equal:today',
            'version' => 'prohibited', 'activity_snapshot' => 'prohibited', 'activity_version_id' => 'prohibited', 'assessed_by' => 'prohibited', 'assessed_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function appendVersion(PrivacyProcessingActivity $activity, User $actor, string $summary, int $version, $at): PrivacyActivityVersion
    {
        $payload = ['privacy_processing_activity_id' => $activity->id, 'version' => $version, 'activity_snapshot' => $this->snapshot($activity->refresh()), 'change_summary' => $summary, 'recorded_by' => $actor->id, 'recorded_at' => $at->toIso8601String()];

        return $activity->versions()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
    }

    /** @return array<string,mixed> */
    private function snapshot(PrivacyProcessingActivity $activity): array
    {
        $activity->load('owner:id,name,email');
        $snapshot = $activity->only(['id', 'number', 'name', 'status', 'purpose', 'lawful_basis', 'data_subject_categories', 'personal_data_categories', 'special_category_data', 'recipient_categories', 'systems_and_vendors', 'processing_locations', 'cross_border_transfer', 'transfer_safeguards', 'retention_period', 'security_measures', 'source_reference', 'next_review_at', 'governed_at']) + ['owner' => $activity->owner?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $context */
    private function assertTransferContext(array $context): void
    {
        if ((bool) ($context['cross_border_transfer'] ?? false) && blank($context['transfer_safeguards'] ?? null)) {
            throw ValidationException::withMessages(['transfer_safeguards' => 'Cross-border processing requires a deliberate transfer-safeguards statement.']);
        }
    }
}
