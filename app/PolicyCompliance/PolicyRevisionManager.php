<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyRevisionDecision;
use App\Enums\PolicyRevisionStatus;
use App\Models\Control;
use App\Models\Implementation;
use App\Models\Policy;
use App\Models\PolicyRevision;
use App\Models\PolicyRevisionReview;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PolicyRevisionManager
{
    public function submit(Policy $policy, User $actor, array $data): PolicyRevision
    {
        return DB::transaction(function () use ($policy, $actor, $data): PolicyRevision {
            $locked = Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $this->authorizeWorkspace($locked, $actor, true);
            $validated = Validator::make($data, self::submissionRules())->validate();
            if ($locked->revisions()->where('status', PolicyRevisionStatus::PendingReview)->exists()) {
                throw ValidationException::withMessages(['policy' => 'The current pending revision must be reviewed first.']);
            }

            $version = ((int) $locked->revisions()->max('version')) + 1;
            $snapshot = $this->snapshot($locked, $validated['proposed_effective_date'], true);
            $payload = [
                'policy_id' => $locked->id, 'version' => $version,
                'change_summary' => $validated['change_summary'],
                'proposed_effective_date' => $validated['proposed_effective_date'],
                'policy_snapshot' => $snapshot,
            ];

            return $locked->revisions()->create($payload + [
                'status' => PolicyRevisionStatus::PendingReview,
                'submitted_by' => $actor->id, 'submitted_at' => now()->startOfSecond(),
                'fingerprint' => self::fingerprint($payload),
            ])->load(['submitter:id,name', 'review.reviewer:id,name']);
        }, 3);
    }

    public function review(PolicyRevision $revision, User $actor, array $data): PolicyRevisionReview
    {
        return DB::transaction(function () use ($revision, $actor, $data): PolicyRevisionReview {
            $policyId = PolicyRevision::query()->whereKey($revision->id)->value('policy_id');
            $policy = Policy::query()->lockForUpdate()->findOrFail($policyId);
            $locked = PolicyRevision::query()->lockForUpdate()->findOrFail($revision->id);
            abort_unless($actor->can('Update Policies'), 403, 'You cannot review policy revisions.');
            if ($locked->submitted_by === $actor->id) {
                throw ValidationException::withMessages(['reviewer' => 'The submitter cannot review their own revision.']);
            }
            if ($locked->status !== PolicyRevisionStatus::PendingReview || $locked->review()->exists()) {
                throw ValidationException::withMessages(['revision' => 'Only a pending unreviewed revision can be reviewed.']);
            }
            if ($policy->revisions()->max('version') !== $locked->version) {
                throw ValidationException::withMessages(['revision' => 'Only the latest policy revision can be reviewed.']);
            }
            $validated = Validator::make($data, self::reviewRules())->validate();
            $decision = PolicyRevisionDecision::from($validated['decision']);
            $current = $this->snapshot($policy, $locked->proposed_effective_date->toDateString(), true);
            if ($decision === PolicyRevisionDecision::Approved && $current !== $locked->policy_snapshot) {
                throw ValidationException::withMessages(['revision' => 'The policy or its governed mappings changed after submission. Reject this stale revision before submitting a replacement.']);
            }
            $reviewedAt = now()->startOfSecond();
            $snapshot = [
                'id' => $locked->id, 'policy_id' => $locked->policy_id, 'version' => $locked->version,
                'status' => $locked->status->value, 'change_summary' => $locked->change_summary,
                'proposed_effective_date' => $locked->proposed_effective_date->toDateString(),
                'policy_snapshot' => $locked->policy_snapshot, 'submitted_by' => $locked->submitted_by,
                'submitted_at' => $locked->submitted_at->toISOString(), 'fingerprint' => $locked->fingerprint,
            ];
            $payload = [
                'policy_revision_id' => $locked->id, 'decision' => $decision->value,
                'review_summary' => $validated['review_summary'], 'revision_snapshot' => $snapshot,
                'reviewed_by' => $actor->id, 'reviewed_at' => $reviewedAt->toISOString(),
            ];
            $review = $locked->review()->create($payload + ['fingerprint' => self::fingerprint($payload)]);
            $locked->update(['status' => $decision === PolicyRevisionDecision::Approved
                ? PolicyRevisionStatus::Approved : PolicyRevisionStatus::Rejected]);
            if ($decision === PolicyRevisionDecision::Approved) {
                $policy->update(['effective_date' => $locked->proposed_effective_date]);
            }

            return $review->load(['reviewer:id,name', 'revision.submitter:id,name']);
        }, 3);
    }

    public function history(Policy $policy, User $actor): Builder
    {
        $this->authorizeWorkspace($policy, $actor);

        return PolicyRevision::query()->where('policy_id', $policy->id)
            ->with(['submitter:id,name', 'review.reviewer:id,name'])->latest('version');
    }

    public function governanceStatus(Policy $policy): string
    {
        $latest = $policy->revisions()->latest('version')->first();
        if ($latest?->status === PolicyRevisionStatus::PendingReview) {
            return 'pending_review';
        }
        $approved = $policy->currentApprovedRevision()->first();
        if (! $approved) {
            return 'unpublished';
        }
        if ($this->snapshot($policy, $policy->effective_date?->toDateString(), false) !== $approved->policy_snapshot) {
            return 'revision_required';
        }

        return $approved->proposed_effective_date->isFuture() ? 'approved_scheduled' : 'current';
    }

    public static function submissionRules(): array
    {
        return [
            'change_summary' => ['required', 'string', 'max:30000'],
            'proposed_effective_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public static function reviewRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(PolicyRevisionDecision::class)],
            'review_summary' => ['required', 'string', 'max:30000'],
        ];
    }

    private function authorizeWorkspace(Policy $policy, User $actor, bool $write = false): void
    {
        $allowed = $actor->can($write ? 'Update Policies' : 'Read Policies') || $policy->owner_id === $actor->id;
        abort_unless($allowed, 403, 'You cannot access this policy revision workspace.');
    }

    protected function snapshot(Policy $policy, ?string $effectiveDate, bool $lock): array
    {
        $lockRows = fn (Builder $query): Builder => $lock ? $query->lockForUpdate() : $query;
        $risks = $lockRows(Risk::query()->whereIn('id', $policy->risks()->pluck('risks.id')))->orderBy('id')->get();
        $controls = $lockRows(Control::query()->whereIn('id', $policy->controls()->pluck('controls.id')))->orderBy('id')->get();
        $implementations = $lockRows(Implementation::query()->whereIn('id', $policy->implementations()->pluck('implementations.id')))->orderBy('id')->get();

        $snapshot = $policy->only([
            'id', 'code', 'name', 'document_type', 'policy_scope', 'purpose', 'body', 'document_path',
            'scope_id', 'department_id', 'status_id', 'owner_id', 'retired_date', 'revision_history',
        ]) + [
            'effective_date' => $effectiveDate,
            'risks' => $risks->map(fn (Risk $risk): array => $risk->only(['id', 'code', 'name', 'domain', 'status', 'owner_id', 'likelihood', 'impact', 'residual_score']))->all(),
            'controls' => $controls->map(fn (Control $control): array => $control->only(['id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'control_owner_id']))->all(),
            'implementations' => $implementations->map(fn (Implementation $implementation): array => $implementation->only(['id', 'implementation_status', 'implementation_owner_id', 'effectiveness_rating', 'implementation_date', 'review_date']))->all(),
        ];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
