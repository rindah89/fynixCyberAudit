<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyExceptionMonitoringOutcome;
use App\Enums\PolicyExceptionStatus;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\PolicyExceptionDecision;
use App\Models\PolicyExceptionMonitoringReview;
use App\Models\User;
use App\Services\GovernanceIssueLifecycleManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PolicyExceptionMonitoringManager
{
    public function history(PolicyException $exception, User $actor): Builder
    {
        $policy = $exception->policy;
        abort_unless($policy?->owner_id === $actor->id || $actor->can('Read Policies') || $actor->can('Update Policies'), 403);

        return $exception->monitoringReviews()->with(['reviewer:id,name', 'issue.lifecycle'])->latest('version')->getQuery();
    }

    public function review(PolicyException $exception, User $actor, array $data): PolicyExceptionMonitoringReview
    {
        return DB::transaction(function () use ($exception, $actor, $data): PolicyExceptionMonitoringReview {
            $policyId = PolicyException::withTrashed()->whereKey($exception->id)->value('policy_id');
            $policy = Policy::withTrashed()->lockForUpdate()->findOrFail($policyId);
            $locked = PolicyException::withTrashed()->lockForUpdate()->findOrFail($exception->id);
            abort_unless($actor->can('Update Policies'), 403, 'You cannot monitor policy exceptions.');
            $validated = Validator::make($data, self::rules())->validate();
            if (! $locked->governance_fingerprint || $locked->status !== PolicyExceptionStatus::Approved || ! $locked->isActive()) {
                throw ValidationException::withMessages(['exception' => 'Only an active governed approved exception can be monitored.']);
            }
            $latestDecision = PolicyExceptionDecision::query()->where('policy_exception_id', $locked->id)
                ->latest('version')->lockForUpdate()->firstOrFail();
            if (in_array($actor->id, [$locked->requested_by, $latestDecision->decided_by], true)) {
                throw ValidationException::withMessages(['reviewer' => 'Monitoring must be performed by a user independent of the requester and latest decision maker.']);
            }
            $frequency = (int) $locked->review_frequency_days;
            if ($frequency < 1) {
                throw ValidationException::withMessages(['exception' => 'The approved exception has no governed monitoring frequency.']);
            }
            $reviewedAt = now()->startOfSecond();
            $nextReviewAt = $reviewedAt->copy()->addDays($frequency);
            if ($locked->expiration_date && $nextReviewAt->greaterThan($locked->expiration_date->endOfDay())) {
                $nextReviewAt = $locked->expiration_date->copy()->endOfDay();
            }
            $version = ((int) $locked->monitoringReviews()->max('version')) + 1;
            $currentPolicyContext = app(PolicyExceptionGovernanceManager::class)->currentPolicyContext($policy, true);
            $approvedPolicyContext = collect($locked->governance_snapshot)
                ->only(['policy', 'approved_revision', 'revision_governance_status', 'deleted_at'])->all();
            $approvalContextCurrent = $currentPolicyContext === $approvedPolicyContext;
            if ($validated['outcome'] === PolicyExceptionMonitoringOutcome::Effective->value && ! $approvalContextCurrent) {
                throw ValidationException::withMessages(['outcome' => 'An exception with changed policy context cannot be confirmed effective. Record an action-required outcome.']);
            }
            $snapshot = self::snapshot($locked, $latestDecision, $approvedPolicyContext, $currentPolicyContext, $approvalContextCurrent);
            $payload = [
                'policy_exception_id' => $locked->id,
                'version' => $version,
                'outcome' => $validated['outcome'],
                'review_summary' => $validated['review_summary'],
                'control_effectiveness' => $validated['control_effectiveness'],
                'evidence_reference' => $validated['evidence_reference'] ?? null,
                'exception_snapshot' => $snapshot,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt->toISOString(),
                'next_review_at' => $nextReviewAt->toISOString(),
            ];
            $review = $locked->monitoringReviews()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ]);
            $locked->update([
                'latest_monitoring_outcome' => PolicyExceptionMonitoringOutcome::from($validated['outcome']),
                'next_review_at' => $nextReviewAt,
            ]);

            if ($review->outcome !== PolicyExceptionMonitoringOutcome::Effective) {
                $issue = $review->issue()->create([
                    'policy_exception_id' => $locked->id,
                    'owner_id' => $policy->owner_id,
                    'title' => "Policy exception {$locked->name} requires action",
                    'description' => $review->review_summary."\n\nCompensating-control assessment: ".$review->control_effectiveness,
                    'severity' => $review->outcome === PolicyExceptionMonitoringOutcome::RevokeRecommended ? 'high' : 'medium',
                    'status' => 'open',
                ]);
                $lifecycle = app(GovernanceIssueLifecycleManager::class)->register($issue, $actor);
                $lifecycle->update(['due_at' => $locked->expiration_date]);
            }

            return $review->load(['reviewer:id,name', 'exception.policy:id,code,name', 'issue.lifecycle']);
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(PolicyExceptionMonitoringOutcome::class)],
            'review_summary' => ['required', 'string', 'max:30000'],
            'control_effectiveness' => ['required', 'string', 'max:30000'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function snapshot(
        PolicyException $exception,
        PolicyExceptionDecision $decision,
        array $approvedPolicyContext,
        array $currentPolicyContext,
        bool $approvalContextCurrent,
    ): array {
        return [
            'id' => $exception->id,
            'policy_id' => $exception->policy_id,
            'status' => $exception->status->value,
            'name' => $exception->name,
            'description' => $exception->description,
            'justification' => $exception->justification,
            'risk_assessment' => $exception->risk_assessment,
            'compensating_controls' => $exception->compensating_controls,
            'effective_date' => $exception->effective_date?->toDateString(),
            'expiration_date' => $exception->expiration_date?->toDateString(),
            'review_frequency_days' => $exception->review_frequency_days,
            'next_review_at' => $exception->next_review_at?->toISOString(),
            'latest_monitoring_outcome' => $exception->latest_monitoring_outcome?->value,
            'requested_by' => $exception->requested_by,
            'requested_date' => $exception->requested_date?->toDateString(),
            'submitted_at' => $exception->submitted_at?->toISOString(),
            'approved_by' => $exception->approved_by,
            'created_by' => $exception->created_by,
            'updated_by' => $exception->updated_by,
            'created_at' => $exception->created_at?->toISOString(),
            'updated_at' => $exception->updated_at?->toISOString(),
            'deleted_at' => $exception->deleted_at?->toISOString(),
            'governance_snapshot' => $exception->governance_snapshot,
            'governance_fingerprint' => $exception->governance_fingerprint,
            'latest_decision' => [
                'id' => $decision->id,
                'policy_exception_id' => $decision->policy_exception_id,
                'version' => $decision->version,
                'decision' => $decision->decision->value,
                'decision_summary' => $decision->decision_summary,
                'exception_snapshot' => $decision->exception_snapshot,
                'decided_by' => $decision->decided_by,
                'decided_at' => $decision->decided_at?->toISOString(),
                'fingerprint' => $decision->fingerprint,
                'created_at' => $decision->created_at?->toISOString(),
                'updated_at' => $decision->updated_at?->toISOString(),
            ],
            'approved_policy_context' => $approvedPolicyContext,
            'current_policy_context' => $currentPolicyContext,
            'approval_context_current' => $approvalContextCurrent,
        ];
    }
}
