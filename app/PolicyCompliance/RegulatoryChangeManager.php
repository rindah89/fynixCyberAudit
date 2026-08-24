<?php

namespace App\PolicyCompliance;

use App\Enums\RegulatoryApplicability;
use App\Enums\RegulatoryChangeType;
use App\Enums\RegulatoryImpact;
use App\Enums\RegulatoryRequirementStatus;
use App\Enums\RegulatorySourceStatus;
use App\Models\Control;
use App\Models\Policy;
use App\Models\RegulatoryChangeAssessment;
use App\Models\RegulatoryRequirement;
use App\Models\RegulatoryRequirementVersion;
use App\Models\RegulatorySource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegulatoryChangeManager
{
    public function createSource(User $actor, array $data): RegulatorySource
    {
        $this->authorizePolicyEditor($actor);
        $validated = Validator::make($data, self::sourceRules())->validate();

        return DB::transaction(function () use ($actor, $validated): RegulatorySource {
            if (! User::query()->whereKey($validated['owner_id'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['owner_id' => 'The regulatory source owner must be active.']);
            }

            return RegulatorySource::query()->create($validated + ['created_by' => $actor->id, 'updated_by' => $actor->id]);
        }, 3);
    }

    public function updateSource(RegulatorySource $source, User $actor, array $data): RegulatorySource
    {
        return DB::transaction(function () use ($source, $actor, $data): RegulatorySource {
            $locked = RegulatorySource::query()->lockForUpdate()->findOrFail($source->id);
            $this->authorizeSource($locked, $actor);
            $validated = Validator::make($data, self::sourceRules($locked))->validate();
            if (! User::query()->whereKey($validated['owner_id'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['owner_id' => 'The regulatory source owner must be active.']);
            }
            $locked->update($validated + ['updated_by' => $actor->id]);

            return $locked->load(['owner:id,name', 'updater:id,name']);
        }, 3);
    }

    public function createRequirement(RegulatorySource $source, User $actor, array $data): RegulatoryRequirement
    {
        return DB::transaction(function () use ($source, $actor, $data): RegulatoryRequirement {
            $lockedSource = RegulatorySource::query()->lockForUpdate()->findOrFail($source->id);
            $this->authorizeSource($lockedSource, $actor);
            if ($lockedSource->status !== RegulatorySourceStatus::Active) {
                throw ValidationException::withMessages(['source' => 'Requirements can be published only from an active regulatory source.']);
            }
            $validated = Validator::make($data, self::requirementRules())->validate();
            if ($lockedSource->requirements()->withTrashed()->where('code', $validated['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'This regulatory source already has a requirement with that code.']);
            }
            if (RegulatoryChangeType::from($validated['change_type']) !== RegulatoryChangeType::NewRequirement) {
                throw ValidationException::withMessages(['change_type' => 'The first version must use new_requirement change type.']);
            }
            if (! User::query()->whereKey($validated['owner_id'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['owner_id' => 'The requirement owner must be active.']);
            }
            $requirement = $lockedSource->requirements()->create([
                'code' => $validated['code'], 'owner_id' => $validated['owner_id'], 'created_by' => $actor->id,
            ]);
            $this->publishLocked($requirement, $lockedSource, $actor, $validated);

            return $requirement->load(['source', 'owner:id,name', 'latestVersion.latestAssessment']);
        }, 3);
    }

    public function publishVersion(RegulatoryRequirement $requirement, User $actor, array $data): RegulatoryRequirementVersion
    {
        return DB::transaction(function () use ($requirement, $actor, $data): RegulatoryRequirementVersion {
            $locked = RegulatoryRequirement::query()->lockForUpdate()->findOrFail($requirement->id);
            $source = RegulatorySource::query()->lockForUpdate()->findOrFail($locked->regulatory_source_id);
            $this->authorizeRequirement($locked, $actor);
            if ($source->status !== RegulatorySourceStatus::Active) {
                throw ValidationException::withMessages(['source' => 'A new version requires an active regulatory source.']);
            }
            $validated = Validator::make($data, self::versionRules())->validate();
            if (RegulatoryChangeType::from($validated['change_type']) === RegulatoryChangeType::NewRequirement) {
                throw ValidationException::withMessages(['change_type' => 'Later versions must describe an amendment, guidance, or repeal.']);
            }

            return $this->publishLocked($locked, $source, $actor, $validated);
        }, 3);
    }

    public function assess(RegulatoryRequirementVersion $version, User $actor, array $data): RegulatoryChangeAssessment
    {
        return DB::transaction(function () use ($version, $actor, $data): RegulatoryChangeAssessment {
            $requirementId = RegulatoryRequirementVersion::query()->whereKey($version->id)->value('regulatory_requirement_id');
            $requirement = RegulatoryRequirement::query()->lockForUpdate()->findOrFail($requirementId);
            $source = RegulatorySource::withTrashed()->lockForUpdate()->findOrFail($requirement->regulatory_source_id);
            $lockedVersion = RegulatoryRequirementVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->authorizeRequirement($requirement, $actor);
            if ($requirement->latestVersion()->value('id') !== $lockedVersion->id) {
                throw ValidationException::withMessages(['version' => 'Only the current regulatory requirement version can be assessed.']);
            }
            $validated = Validator::make($data, self::assessmentRules())->validate();
            $applicability = RegulatoryApplicability::from($validated['applicability']);
            $impact = RegulatoryImpact::from($validated['impact']);
            if ($applicability === RegulatoryApplicability::UnderReview && (! isset($validated['action_owner_id']) || ! isset($validated['action_due_at']))) {
                throw ValidationException::withMessages(['action_owner_id' => 'An owner and due date are required while applicability is under review.']);
            }
            if ($applicability === RegulatoryApplicability::NotApplicable && (isset($validated['action_owner_id']) || isset($validated['action_due_at']))) {
                throw ValidationException::withMessages(['action_owner_id' => 'Not-applicable assessments cannot assign an implementation action.']);
            }
            if (in_array($impact, [RegulatoryImpact::High, RegulatoryImpact::Critical], true)
                && $applicability === RegulatoryApplicability::Applicable
                && (! isset($validated['action_owner_id']) || ! isset($validated['action_due_at']))) {
                throw ValidationException::withMessages(['action_owner_id' => 'High and critical applicable changes require an action owner and due date.']);
            }
            if (isset($validated['action_owner_id']) xor isset($validated['action_due_at'])) {
                throw ValidationException::withMessages(['action_owner_id' => 'An action owner and due date must be supplied together.']);
            }
            if (isset($validated['action_owner_id']) && ! User::query()->whereKey($validated['action_owner_id'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['action_owner_id' => 'The action owner must be active.']);
            }
            $assessmentVersion = ((int) $lockedVersion->assessments()->max('assessment_version')) + 1;
            $assessedAt = now();
            $requirementSnapshot = [
                'requirement' => $requirement->only(['id', 'regulatory_source_id', 'code', 'owner_id']),
                'version' => $lockedVersion->only(['id', 'version', 'change_type', 'status', 'title', 'requirement_text', 'effective_at', 'expires_at', 'policy_ids', 'control_ids', 'source_snapshot', 'policy_snapshots', 'control_snapshots', 'content_fingerprint', 'published_by', 'published_at']),
                'source' => $source->only(['id', 'code', 'title', 'authority', 'jurisdiction', 'reference_url', 'owner_id', 'status']),
            ];

            return $lockedVersion->assessments()->create([
                'assessment_version' => $assessmentVersion, 'applicability' => $applicability, 'impact' => $impact,
                'summary' => $validated['summary'], 'rationale' => $validated['rationale'],
                'action_owner_id' => $validated['action_owner_id'] ?? null, 'action_due_at' => $validated['action_due_at'] ?? null,
                'requirement_snapshot' => $requirementSnapshot,
                'policy_snapshots' => $lockedVersion->policy_snapshots,
                'control_snapshots' => $lockedVersion->control_snapshots,
                'content_fingerprint' => $lockedVersion->content_fingerprint,
                'assessed_by' => $actor->id, 'assessed_at' => $assessedAt,
            ])->load(['assessor:id,name', 'actionOwner:id,name']);
        }, 3);
    }

    public function requirements(User $actor): Builder
    {
        $query = RegulatoryRequirement::query()->with([
            'source:id,code,title,authority,jurisdiction,owner_id,status', 'owner:id,name',
            'latestVersion.latestAssessment.actionOwner:id,name',
        ]);
        if (! $actor->can('Read Policies') && ! $actor->can('Update Policies')) {
            $query->where(fn (Builder $scope): Builder => $scope
                ->where('owner_id', $actor->id)
                ->orWhereHas('source', fn (Builder $source): Builder => $source->where('owner_id', $actor->id)));
        }

        return $query->latest();
    }

    public function versionHistory(RegulatoryRequirement $requirement, User $actor): Builder
    {
        $this->authorizeRequirement($requirement, $actor, true);

        return RegulatoryRequirementVersion::query()->where('regulatory_requirement_id', $requirement->id)
            ->with('publisher:id,name')->latest('version');
    }

    public function assessmentHistory(RegulatoryRequirement $requirement, User $actor): Builder
    {
        $this->authorizeRequirement($requirement, $actor, true);

        return RegulatoryChangeAssessment::query()->whereHas('requirementVersion', fn (Builder $version): Builder => $version
            ->where('regulatory_requirement_id', $requirement->id))->with([
                'requirementVersion:id,regulatory_requirement_id,version,title', 'assessor:id,name', 'actionOwner:id,name',
            ])->latest('assessed_at');
    }

    public static function sourceRules(?RegulatorySource $source = null): array
    {
        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('regulatory_sources')->ignore($source)],
            'title' => ['required', 'string', 'max:255'], 'authority' => ['required', 'string', 'max:255'],
            'jurisdiction' => ['required', 'string', 'max:255'], 'reference_url' => ['nullable', 'url', 'max:2048'],
            'owner_id' => ['required', 'integer', 'exists:users,id'], 'status' => ['required', Rule::enum(RegulatorySourceStatus::class)],
        ];
    }

    public static function requirementRules(): array
    {
        return ['code' => ['required', 'string', 'max:255'], 'owner_id' => ['required', 'integer', 'exists:users,id']] + self::versionRules();
    }

    public static function versionRules(): array
    {
        return [
            'change_type' => ['required', Rule::enum(RegulatoryChangeType::class)],
            'status' => ['required', Rule::enum(RegulatoryRequirementStatus::class)],
            'title' => ['required', 'string', 'max:255'], 'requirement_text' => ['required', 'string', 'max:100000'],
            'effective_at' => ['required', 'date_format:Y-m-d'], 'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:effective_at'],
            'policy_ids' => ['sometimes', 'array', 'max:100'], 'policy_ids.*' => ['integer', 'distinct', 'exists:policies,id'],
            'control_ids' => ['sometimes', 'array', 'max:250'], 'control_ids.*' => ['integer', 'distinct', 'exists:controls,id'],
        ];
    }

    public static function assessmentRules(): array
    {
        return [
            'applicability' => ['required', Rule::enum(RegulatoryApplicability::class)],
            'impact' => ['required', Rule::enum(RegulatoryImpact::class)],
            'summary' => ['required', 'string', 'max:30000'], 'rationale' => ['required', 'string', 'max:30000'],
            'action_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'action_due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    private function publishLocked(RegulatoryRequirement $requirement, RegulatorySource $source, User $actor, array $data): RegulatoryRequirementVersion
    {
        $changeType = RegulatoryChangeType::from($data['change_type']);
        $status = RegulatoryRequirementStatus::from($data['status']);
        if (($changeType === RegulatoryChangeType::Repeal) !== ($status === RegulatoryRequirementStatus::Repealed)) {
            throw ValidationException::withMessages(['status' => 'A repeal change must use repealed status, and repealed status requires a repeal change.']);
        }
        $policyIds = collect($data['policy_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $controlIds = collect($data['control_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $policies = Policy::query()->whereKey($policyIds)->orderBy('id')->lockForUpdate()->get();
        $controls = Control::query()->whereKey($controlIds)->orderBy('id')->lockForUpdate()->get();
        if ($policies->count() !== $policyIds->count() || $controls->count() !== $controlIds->count()) {
            throw ValidationException::withMessages(['mappings' => 'Every mapped policy and control must remain active and available.']);
        }
        $version = ((int) $requirement->versions()->max('version')) + 1;
        $sourceSnapshot = $source->only(['id', 'code', 'title', 'authority', 'jurisdiction', 'reference_url', 'owner_id', 'status', 'updated_at']);
        $policySnapshots = $policies->map(fn (Policy $policy): array => $policy->only(['id', 'code', 'name', 'owner_id', 'effective_date', 'retired_date', 'updated_at']))->all();
        $controlSnapshots = $controls->map(fn (Control $control): array => $control->only(['id', 'standard_id', 'control_owner_id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']))->all();
        $payload = [
            'requirement_id' => $requirement->id, 'requirement_code' => $requirement->code, 'requirement_owner_id' => $requirement->owner_id,
            'version' => $version, 'change_type' => $changeType->value, 'status' => $status->value,
            'title' => $data['title'], 'requirement_text' => $data['requirement_text'],
            'effective_at' => $data['effective_at'], 'expires_at' => $data['expires_at'] ?? null,
            'policy_ids' => $policyIds->all(), 'control_ids' => $controlIds->all(), 'source_snapshot' => $sourceSnapshot,
            'policy_snapshots' => $policySnapshots, 'control_snapshots' => $controlSnapshots,
        ];

        return $requirement->versions()->create([
            'version' => $version, 'change_type' => $changeType, 'status' => $status,
            'title' => $data['title'], 'requirement_text' => $data['requirement_text'],
            'effective_at' => $data['effective_at'], 'expires_at' => $data['expires_at'] ?? null,
            'policy_ids' => $policyIds->all(), 'control_ids' => $controlIds->all(),
            'source_snapshot' => $sourceSnapshot, 'policy_snapshots' => $policySnapshots, 'control_snapshots' => $controlSnapshots,
            'content_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'published_by' => $actor->id, 'published_at' => now(),
        ])->load('publisher:id,name');
    }

    private function authorizePolicyEditor(User $actor): void
    {
        if (! $actor->can('Update Policies')) {
            abort(403, 'You cannot manage regulatory sources.');
        }
    }

    private function authorizeSource(RegulatorySource $source, User $actor): void
    {
        if (! $actor->can('Update Policies') && $source->owner_id !== $actor->id) {
            abort(403, 'You cannot manage this regulatory source.');
        }
    }

    private function authorizeRequirement(RegulatoryRequirement $requirement, User $actor, bool $allowReader = false): void
    {
        if ((! $allowReader || ! $actor->can('Read Policies')) && ! $actor->can('Update Policies') && $requirement->owner_id !== $actor->id && $requirement->source()->where('owner_id', $actor->id)->doesntExist()) {
            abort(403, 'You cannot assess this regulatory requirement.');
        }
    }
}
