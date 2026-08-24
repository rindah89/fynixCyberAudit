<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyExceptionDecisionType;
use App\Enums\PolicyExceptionStatus;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\PolicyExceptionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PolicyExceptionGovernanceManager
{
    public function submit(Policy $policy, User $actor, array $data): PolicyException
    {
        return DB::transaction(function () use ($policy, $actor, $data): PolicyException {
            $locked = Policy::withTrashed()->lockForUpdate()->findOrFail($policy->id);
            $this->authorizeWorkspace($locked, $actor);
            if ($locked->trashed()) {
                throw ValidationException::withMessages(['policy' => 'A deleted policy cannot receive a new exception request.']);
            }
            $validated = Validator::make($data, self::requestRules())->validate();
            $requestedAt = now()->startOfSecond();
            $context = $this->currentPolicyContext($locked, true);
            $request = [
                'name' => $validated['name'], 'description' => $validated['description'] ?? null,
                'justification' => $validated['justification'], 'risk_assessment' => $validated['risk_assessment'],
                'compensating_controls' => $validated['compensating_controls'],
                'effective_date' => $validated['effective_date'], 'expiration_date' => $validated['expiration_date'],
                'review_frequency_days' => $validated['review_frequency_days'] ?? 90,
            ];
            $snapshot = $context + ['request' => $request];
            $payload = ['policy_id' => $locked->id, 'requested_by' => $actor->id, 'requested_at' => $requestedAt->toISOString(), 'governance_snapshot' => $snapshot];

            return $locked->exceptions()->create($request + [
                'status' => PolicyExceptionStatus::Pending,
                'requested_by' => $actor->id, 'requested_date' => $requestedAt->toDateString(),
                'submitted_at' => $requestedAt, 'created_by' => $actor->id,
                'governance_snapshot' => $snapshot,
                'governance_fingerprint' => self::fingerprint($payload),
            ])->load(['policy:id,code,name', 'requester:id,name', 'decisions.decider:id,name']);
        }, 3);
    }

    public function decide(PolicyException $exception, User $actor, array $data): PolicyExceptionDecision
    {
        return DB::transaction(function () use ($exception, $actor, $data): PolicyExceptionDecision {
            $policyId = PolicyException::withTrashed()->whereKey($exception->id)->value('policy_id');
            $policy = Policy::withTrashed()->lockForUpdate()->findOrFail($policyId);
            $locked = PolicyException::withTrashed()->lockForUpdate()->findOrFail($exception->id);
            abort_unless($actor->can('Update Policies'), 403, 'You cannot decide policy exception requests.');
            if (! $locked->governance_fingerprint) {
                throw ValidationException::withMessages(['exception' => 'Legacy policy exceptions are outside the governed decision lifecycle.']);
            }
            if ($locked->requested_by === $actor->id) {
                throw ValidationException::withMessages(['decider' => 'The requester cannot decide their own policy exception.']);
            }
            $validated = Validator::make($data, self::decisionRules())->validate();
            $decision = PolicyExceptionDecisionType::from($validated['decision']);
            $this->assertDecisionAllowed($locked, $decision);
            if ($decision === PolicyExceptionDecisionType::Approved
                && $this->currentPolicyContext($policy, true) !== collect($locked->governance_snapshot)->only(['policy', 'approved_revision', 'revision_governance_status', 'deleted_at'])->all()) {
                throw ValidationException::withMessages(['exception' => 'The policy approval context changed. Deny this stale request before submitting a replacement.']);
            }
            $version = ((int) $locked->decisions()->max('version')) + 1;
            $decidedAt = now()->startOfSecond();
            $snapshot = [
                'id' => $locked->id, 'policy_id' => $locked->policy_id, 'status' => $locked->status->value,
                'name' => $locked->name, 'description' => $locked->description, 'justification' => $locked->justification,
                'risk_assessment' => $locked->risk_assessment, 'compensating_controls' => $locked->compensating_controls,
                'effective_date' => $locked->effective_date?->toDateString(), 'expiration_date' => $locked->expiration_date?->toDateString(),
                'review_frequency_days' => $locked->review_frequency_days,
                'requested_by' => $locked->requested_by, 'requested_date' => $locked->requested_date?->toDateString(),
                'submitted_at' => $locked->submitted_at?->toISOString(), 'governance_snapshot' => $locked->governance_snapshot,
                'governance_fingerprint' => $locked->governance_fingerprint,
            ];
            $payload = [
                'policy_exception_id' => $locked->id, 'version' => $version, 'decision' => $decision->value,
                'decision_summary' => $validated['decision_summary'], 'exception_snapshot' => $snapshot,
                'decided_by' => $actor->id, 'decided_at' => $decidedAt->toISOString(),
            ];
            $record = $locked->decisions()->create($payload + ['fingerprint' => self::fingerprint($payload)]);
            $monitoringStart = now()->startOfSecond();
            if ($locked->effective_date->startOfDay()->greaterThan($monitoringStart)) {
                $monitoringStart = $locked->effective_date->startOfDay();
            }
            $initialReviewAt = min(
                $monitoringStart->addDays((int) $locked->review_frequency_days),
                $locked->expiration_date->endOfDay(),
            );
            $locked->update([
                'status' => PolicyExceptionStatus::from($decision->value),
                'approved_by' => $decision === PolicyExceptionDecisionType::Approved ? $actor->id : $locked->approved_by,
                'next_review_at' => $decision === PolicyExceptionDecisionType::Approved
                    ? $initialReviewAt
                    : $locked->next_review_at,
            ]);

            return $record->load(['decider:id,name', 'exception.requester:id,name', 'exception.decisions.decider:id,name']);
        }, 3);
    }

    public function history(Policy $policy, User $actor): Builder
    {
        $this->authorizeWorkspace($policy, $actor);

        return PolicyException::query()->where('policy_id', $policy->id)->whereNotNull('governance_fingerprint')
            ->with([
                'requester:id,name', 'approver:id,name', 'decisions.decider:id,name',
                'monitoringReviews.reviewer:id,name', 'monitoringReviews.issue.lifecycle',
                'openMonitoringIssues' => fn ($issues) => $issues->select([
                    'policy_exception_monitoring_issues.id',
                    'policy_exception_monitoring_issues.policy_exception_monitoring_review_id',
                    'policy_exception_monitoring_issues.status',
                ]),
            ])->latest('submitted_at');
    }

    public static function requestRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:30000'],
            'justification' => ['required', 'string', 'max:30000'], 'risk_assessment' => ['required', 'string', 'max:30000'],
            'compensating_controls' => ['required', 'string', 'max:30000'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'expiration_date' => ['required', 'date_format:Y-m-d', 'after:effective_date'],
            'review_frequency_days' => ['sometimes', 'integer', 'between:1,365'],
        ];
    }

    public static function decisionRules(): array
    {
        return ['decision' => ['required', Rule::enum(PolicyExceptionDecisionType::class)], 'decision_summary' => ['required', 'string', 'max:30000']];
    }

    private function authorizeWorkspace(Policy $policy, User $actor): void
    {
        abort_unless($policy->owner_id === $actor->id || $actor->can('Read Policies') || $actor->can('Update Policies'), 403, 'You cannot access this policy exception workspace.');
    }

    public function currentPolicyContext(Policy $policy, bool $lock = false): array
    {
        $revision = $policy->currentApprovedRevision()->first();
        $revisionManager = app(PolicyRevisionManager::class);

        $context = [
            'policy' => $revisionManager->currentSnapshot($policy, $lock),
            'approved_revision' => $revision?->only(['id', 'version', 'fingerprint', 'proposed_effective_date']),
            'revision_governance_status' => $revisionManager->governanceStatus($policy),
            'deleted_at' => $policy->deleted_at?->toISOString(),
        ];

        return json_decode(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertDecisionAllowed(PolicyException $exception, PolicyExceptionDecisionType $decision): void
    {
        $allowed = ($exception->status === PolicyExceptionStatus::Pending && in_array($decision, [PolicyExceptionDecisionType::Approved, PolicyExceptionDecisionType::Denied], true))
            || ($exception->status === PolicyExceptionStatus::Approved && $decision === PolicyExceptionDecisionType::Revoked);
        if (! $allowed) {
            throw ValidationException::withMessages(['decision' => 'This decision is not allowed from the current exception state.']);
        }
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
