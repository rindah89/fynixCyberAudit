<?php

namespace App\ModelRisk;

use App\Enums\ModelGovernanceStatus;
use App\Enums\ModelLifecycleStatus;
use App\Enums\ModelValidationDecision;
use App\Models\GovernedModel;
use App\Models\GovernedModelVersion;
use App\Models\ModelValidationReview;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModelRiskManager
{
    /** @param array<string,mixed> $data */
    public function register(User $actor, array $data): GovernedModel
    {
        Enterprise::assertEnabled('model_risk_management');
        abort_unless($actor->can('create', GovernedModel::class), 403);
        $data = Validator::make($data, self::modelRules(true))->validate();

        return DB::transaction(function () use ($actor, $data): GovernedModel {
            DB::table('governed_model_mutexes')->where('id', 1)->lockForUpdate()->first();
            [$owner,$developer] = $this->lockAccountableUsers($data['owner_id'], $data['developer_id']);
            $at = now()->startOfSecond();
            $next = ((int) GovernedModel::query()->max('id')) + 1;
            $model = GovernedModel::query()->create([...Arr::except($data, ['change_summary']), 'code' => 'MDL-'.$at->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT), 'lifecycle_status' => ModelLifecycleStatus::Proposed, 'governance_status' => ModelGovernanceStatus::ValidationRequired, 'governed_at' => $at]);
            $this->appendVersion($model, $actor, $data['change_summary'], 1, $at);

            return $model->load(['owner:id,name,email', 'developer:id,name,email', 'versions.actor:id,name']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function revise(User $actor, GovernedModel $model, array $data): GovernedModelVersion
    {
        Enterprise::assertEnabled('model_risk_management');

        return DB::transaction(function () use ($actor, $model, $data): GovernedModelVersion {
            $locked = GovernedModel::query()->lockForUpdate()->findOrFail($model->id);
            abort_unless($actor->can('update', $locked), 403);
            $data = Validator::make($data, self::modelRules(false))->validate();
            $versions = GovernedModelVersion::query()->where('governed_model_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($versions->count() >= 100) {
                throw ValidationException::withMessages(['model' => 'A governed model is limited to 100 versions.']);
            }
            if ($locked->lifecycle_status === ModelLifecycleStatus::Retired) {
                throw ValidationException::withMessages(['model' => 'Retired models are terminal.']);
            }
            $changes = Arr::except($data, ['change_summary']);
            $requestedLifecycle = isset($changes['lifecycle_status']) ? ModelLifecycleStatus::from($changes['lifecycle_status']) : null;
            $candidate = clone $locked;
            $candidate->fill($changes);
            $material = Arr::except(Arr::only($changes, array_keys($candidate->getDirty())), ['lifecycle_status', 'governance_status']);
            if ($requestedLifecycle !== null && $requestedLifecycle !== ModelLifecycleStatus::Retired) {
                throw ValidationException::withMessages(['lifecycle_status' => 'Lifecycle progression is server-derived from validation.']);
            }
            if ($requestedLifecycle === ModelLifecycleStatus::Retired && $material !== []) {
                throw ValidationException::withMessages(['lifecycle_status' => 'Retirement cannot rewrite material model context.']);
            }
            [$owner,$developer] = $this->lockAccountableUsers($changes['owner_id'] ?? $locked->owner_id, $changes['developer_id'] ?? $locked->developer_id);
            $before = $this->snapshot($locked);
            if ($material !== []) {
                $changes['lifecycle_status'] = ModelLifecycleStatus::Development;
                $changes['governance_status'] = ModelGovernanceStatus::ValidationRequired;
            }
            $locked->update($changes);
            if ($before === $this->snapshot($locked->refresh())) {
                throw ValidationException::withMessages(['model' => 'A revision must change governed state.']);
            }

            return $this->appendVersion($locked, $actor, $data['change_summary'], $versions->count() + 1, now()->startOfSecond())->load('actor:id,name');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function validate(User $actor, GovernedModel $model, array $data): ModelValidationReview
    {
        Enterprise::assertEnabled('model_risk_management');

        return DB::transaction(function () use ($actor, $model, $data): ModelValidationReview {
            $locked = GovernedModel::query()->lockForUpdate()->findOrFail($model->id);
            abort_unless($actor->can('validateModel', $locked), 403);
            $data = Validator::make($data, self::validationRules())->validate();
            if ($locked->lifecycle_status === ModelLifecycleStatus::Retired) {
                throw ValidationException::withMessages(['model' => 'Retired models are terminal.']);
            }
            $version = GovernedModelVersion::query()->where('governed_model_id', $locked->id)->latest('version')->lockForUpdate()->firstOrFail();
            $reviews = ModelValidationReview::query()->where('governed_model_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($reviews->count() >= 100) {
                throw ValidationException::withMessages(['validation' => 'A governed model is limited to 100 validation reviews.']);
            }
            abort_if(in_array($actor->id, [$locked->owner_id, $locked->developer_id, $version->recorded_by], true), 403, 'The owner, developer, and latest version author cannot validate that version.');
            $this->lockAccountableUsers($locked->owner_id, $locked->developer_id);
            if ($this->materialSnapshot($version->model_snapshot) !== $this->materialSnapshot($this->snapshot($locked))) {
                throw ValidationException::withMessages(['model' => 'The latest retained model version does not match current material state.']);
            }
            $at = now()->startOfSecond();
            $decision = ModelValidationDecision::from($data['decision']);
            if ($decision === ModelValidationDecision::ConditionallyApproved && $data['conditions'] === []) {
                throw ValidationException::withMessages(['conditions' => 'Conditional approval requires at least one usage condition.']);
            }
            $payload = ['governed_model_id' => $locked->id, 'version' => $reviews->count() + 1, 'model_version_id' => $version->id, 'model_snapshot' => $version->model_snapshot, 'scope' => $data['scope'], 'testing_performed' => $data['testing_performed'], 'findings' => $data['findings'], 'performance_summary' => $data['performance_summary'], 'limitations_assessment' => $data['limitations_assessment'], 'decision' => $decision->value, 'conditions' => $data['conditions'], 'decision_summary' => $data['decision_summary'], 'validated_by' => $actor->id, 'validated_at' => $at->toIso8601String(), 'valid_until' => Carbon::parse($data['valid_until'])->toDateString()];
            $review = $locked->validations()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            [$lifecycle,$governance] = match ($decision) {
                ModelValidationDecision::Approved => [ModelLifecycleStatus::Production, ModelGovernanceStatus::Approved], ModelValidationDecision::ConditionallyApproved => [ModelLifecycleStatus::Production, ModelGovernanceStatus::Restricted], ModelValidationDecision::ChangesRequired => [ModelLifecycleStatus::Development, ModelGovernanceStatus::ValidationRequired], ModelValidationDecision::Rejected => [ModelLifecycleStatus::Development, ModelGovernanceStatus::Rejected]
            };
            $locked->update(['lifecycle_status' => $lifecycle, 'governance_status' => $governance]);

            return $review->load(['validator:id,name', 'modelVersion']);
        }, 3);
    }

    /** @return array<string,mixed> */
    public static function modelRules(bool $creating): array
    {
        $prefix = fn (string $rule): string => ($creating ? 'required' : 'sometimes').'|'.$rule;
        $rules = ['name' => $prefix('string|max:255'), 'model_type' => [$creating ? 'required' : 'sometimes', Rule::in(['Credit', 'Market', 'Financial', 'Operational', 'Compliance', 'Decision Support', 'Statistical', 'Other'])], 'tier' => $prefix('integer|min:1|max:4'), 'owner_id' => $prefix('integer|exists:users,id'), 'developer_id' => $prefix('integer|exists:users,id'), 'intended_use' => $prefix('string|max:30000'), 'methodology' => $prefix('string|max:30000'), 'input_data' => $prefix('array|min:1|max:100'), 'input_data.*' => 'string|max:255|distinct', 'outputs' => $prefix('array|min:1|max:100'), 'outputs.*' => 'string|max:255|distinct', 'assumptions' => $prefix('array|max:100'), 'assumptions.*' => 'string|max:2000|distinct', 'limitations' => $prefix('array|min:1|max:100'), 'limitations.*' => 'string|max:2000|distinct', 'usage_restrictions' => $prefix('array|max:100'), 'usage_restrictions.*' => 'string|max:2000|distinct', 'implementation_reference' => 'sometimes|nullable|string|max:2000', 'change_frequency' => $prefix('string|max:255'), 'next_review_at' => $prefix('date|after_or_equal:today'), 'change_summary' => 'required|string|max:30000', 'lifecycle_status' => ['sometimes', Rule::enum(ModelLifecycleStatus::class)], 'code' => 'prohibited', 'governance_status' => 'prohibited', 'governed_at' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited'];
        if ($creating) {
            $rules['lifecycle_status'] = 'prohibited';
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    public static function validationRules(): array
    {
        return ['scope' => 'required|string|max:30000', 'testing_performed' => 'required|string|max:30000', 'findings' => 'required|array|max:100', 'findings.*' => 'string|max:2000|distinct', 'performance_summary' => 'required|string|max:30000', 'limitations_assessment' => 'required|string|max:30000', 'decision' => ['required', Rule::enum(ModelValidationDecision::class)], 'conditions' => 'present|array|max:100', 'conditions.*' => 'string|max:2000|distinct', 'decision_summary' => 'required|string|max:30000', 'valid_until' => 'required|date|after:today', 'version' => 'prohibited', 'model_snapshot' => 'prohibited', 'model_version_id' => 'prohibited', 'validated_by' => 'prohibited', 'validated_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    /** @return array{User,User} */
    private function lockAccountableUsers(int $ownerId, int $developerId): array
    {
        $users = User::query()->whereNull('deleted_at')->whereIn('id', [$ownerId, $developerId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $owner = $users->get($ownerId);
        $developer = $users->get($developerId);
        if (! $owner?->can('Own Governed Models') && ! $owner?->can('Manage Model Risk')) {
            throw ValidationException::withMessages(['owner_id' => 'The model owner must be active and authorized.']);
        }
        if (! $developer?->can('Develop Governed Models') && ! $developer?->can('Manage Model Risk')) {
            throw ValidationException::withMessages(['developer_id' => 'The model developer must be active and authorized.']);
        }

        return [$owner, $developer];
    }

    private function appendVersion(GovernedModel $model, User $actor, string $summary, int $version, $at): GovernedModelVersion
    {
        $payload = ['governed_model_id' => $model->id, 'version' => $version, 'model_snapshot' => $this->snapshot($model->refresh()), 'change_summary' => $summary, 'recorded_by' => $actor->id, 'recorded_at' => $at->toIso8601String()];

        return $model->versions()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
    }

    /** @return array<string,mixed> */
    private function snapshot(GovernedModel $model): array
    {
        $model->load(['owner:id,name,email', 'developer:id,name,email']);
        $snapshot = $model->only(['id', 'code', 'name', 'model_type', 'tier', 'lifecycle_status', 'intended_use', 'methodology', 'input_data', 'outputs', 'assumptions', 'limitations', 'usage_restrictions', 'implementation_reference', 'change_frequency', 'next_review_at', 'governed_at']) + ['owner' => $model->owner?->only(['id', 'name', 'email']), 'developer' => $model->developer?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function materialSnapshot(array $snapshot): array
    {
        return Arr::except($snapshot, ['lifecycle_status']);
    }

    /** @param array<string,mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
