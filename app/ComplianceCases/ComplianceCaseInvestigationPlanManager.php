<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseInvestigationPlanManager
{
    /** @param array{objectives:list<string>,scope:string,procedures:list<string>,target_completion_at:string,rationale:string} $data */
    public function submit(User $actor, ComplianceCase $case, array $data): ComplianceCaseInvestigationPlan
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseInvestigationPlan {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $manager = $actor->can('Manage Compliance Cases');
            abort_unless($manager || ($actor->can('Investigate Compliance Cases') && $locked->assigned_to === $actor->id), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            if ($locked->status !== ComplianceCaseStatus::Triaged) {
                throw ValidationException::withMessages(['case' => 'Investigation plans may be submitted only while a case is triaged.']);
            }
            foreach (['scope', 'rationale'] as $field) {
                if (isset($data[$field]) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]);
                }
            }
            foreach (['objectives', 'procedures'] as $field) {
                if (is_array($data[$field] ?? null)) {
                    $data[$field] = array_values(array_unique(array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data[$field])));
                }
            }
            $data = Validator::make($data, self::planRules())->validate();
            if (in_array('', $data['objectives'], true) || in_array('', $data['procedures'], true)) {
                throw ValidationException::withMessages(['plan' => 'Objectives and procedures must contain meaningful text.']);
            }
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $plans = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            $reviews = ComplianceCaseInvestigationPlanReview::query()->whereIn('compliance_case_investigation_plan_id', $plans->pluck('id'))->orderBy('compliance_case_investigation_plan_id')->lockForUpdate()->get()->keyBy('compliance_case_investigation_plan_id');
            $plans->each(fn (ComplianceCaseInvestigationPlan $plan) => $plan->setRelation('review', $reviews->get($plan->id)));
            if ($plans->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A compliance case may retain at most 20 investigation plans.']);
            }
            if ($plans->last()?->review === null && $plans->isNotEmpty()) {
                throw ValidationException::withMessages(['case' => 'The latest investigation plan must receive a terminal review before a replacement is submitted.']);
            }
            $submittedAt = now()->startOfSecond();
            $plan = new ComplianceCaseInvestigationPlan([
                'compliance_case_id' => $locked->id, 'version' => $plans->count() + 1, 'objectives' => $data['objectives'], 'scope' => $data['scope'],
                'procedures' => $data['procedures'], 'target_completion_at' => $data['target_completion_at'], 'authored_by' => $actor->id,
                'author_snapshot' => $actor->only(['id', 'name', 'email']), 'case_snapshot' => $this->caseSnapshot($locked, $event),
                'rationale' => $data['rationale'], 'submitted_at' => $submittedAt,
            ]);
            $plan->fingerprint = hash('sha256', CanonicalJson::encode($this->planPayload($plan)));
            $plan->save();

            return $plan->load(['author:id,name,email', 'review.reviewer:id,name,email']);
        }, 3);
    }

    /** @param array{decision:string,summary:string} $data */
    public function review(User $actor, ComplianceCaseInvestigationPlan $plan, array $data): ComplianceCaseInvestigationPlanReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $plan, $data): ComplianceCaseInvestigationPlanReview {
            $caseId = ComplianceCaseInvestigationPlan::query()->whereKey($plan->id)->value('compliance_case_id');
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($caseId);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $locked = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($plan->id);
            abort_unless($actor->can('Manage Compliance Cases'), 403);
            abort_if($actor->id === $locked->authored_by || $actor->id === $case->assigned_to, 403, 'The author and assigned investigator cannot review the plan.');
            if (isset($data['summary']) && is_string($data['summary'])) {
                $data['summary'] = trim($data['summary']);
            }
            $data = Validator::make($data, self::reviewRules())->validate();
            if ($case->status !== ComplianceCaseStatus::Triaged) {
                throw ValidationException::withMessages(['case' => 'Only a triaged case plan can be reviewed.']);
            }
            $latest = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $case->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($latest->id !== $locked->id) {
                throw ValidationException::withMessages(['plan' => 'Only the latest investigation plan can be reviewed.']);
            }
            if (ComplianceCaseInvestigationPlanReview::query()->where('compliance_case_investigation_plan_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['plan' => 'This plan already has a terminal review.']);
            }
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($data['decision'] === ComplianceCaseInvestigationPlanDecision::Approved->value && data_get($locked->case_snapshot, 'event.fingerprint') !== $event->fingerprint) {
                throw ValidationException::withMessages(['plan' => 'Case context changed; submit a new investigation plan.']);
            }
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $reviewedAt = now()->startOfSecond();
            $review = new ComplianceCaseInvestigationPlanReview(['compliance_case_investigation_plan_id' => $locked->id,
                'decision' => ComplianceCaseInvestigationPlanDecision::from($data['decision']), 'summary' => $data['summary'], 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']), 'plan_snapshot' => ['id' => $locked->id] + $this->planPayload($locked) + ['fingerprint' => $locked->fingerprint], 'reviewed_at' => $reviewedAt]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->reviewPayload($review)));
            $review->save();

            return $review->load('reviewer:id,name,email');
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        $case = ComplianceCase::query()->findOrFail($case->id);
        abort_unless($actor->can('view', $case), 403);

        return $case->investigationPlans()->with(['author:id,name,email', 'review.reviewer:id,name,email'])->paginate($perPage);
    }

    public function caseSnapshot(ComplianceCase $case, ComplianceCaseEvent $event): array
    {
        return ['case' => $event->after_snapshot, 'event' => [
            'id' => $event->id, 'compliance_case_id' => $event->compliance_case_id, 'version' => $event->version,
            'event_type' => $event->event_type, 'before_snapshot' => $event->before_snapshot,
            'after_snapshot' => $event->after_snapshot, 'summary' => $event->summary,
            'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at->toIso8601String(),
            'fingerprint' => $event->fingerprint,
        ]];
    }

    public function planPayload(ComplianceCaseInvestigationPlan $plan): array
    {
        return ['compliance_case_id' => $plan->compliance_case_id, 'version' => $plan->version, 'objectives' => $plan->objectives, 'scope' => $plan->scope, 'procedures' => $plan->procedures, 'target_completion_at' => $plan->target_completion_at?->toDateString(), 'authored_by' => $plan->authored_by, 'author_snapshot' => $plan->author_snapshot, 'case_snapshot' => $plan->case_snapshot, 'rationale' => $plan->rationale, 'submitted_at' => $plan->submitted_at?->toIso8601String()];
    }

    public function reviewPayload(ComplianceCaseInvestigationPlanReview $review): array
    {
        return ['compliance_case_investigation_plan_id' => $review->compliance_case_investigation_plan_id, 'decision' => $review->decision instanceof \BackedEnum ? $review->decision->value : $review->decision, 'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by, 'reviewer_snapshot' => $review->reviewer_snapshot, 'plan_snapshot' => $review->plan_snapshot, 'reviewed_at' => $review->reviewed_at?->toIso8601String()];
    }

    public static function planRules(): array
    {
        return ['objectives' => 'required|array|min:1|max:20', 'objectives.*' => 'required|string|max:1000', 'scope' => 'required|string|max:30000', 'procedures' => 'required|array|min:1|max:50', 'procedures.*' => 'required|string|max:2000', 'target_completion_at' => 'required|date|after_or_equal:today', 'rationale' => 'required|string|max:30000', 'id' => 'prohibited', 'compliance_case_id' => 'prohibited', 'version' => 'prohibited', 'authored_by' => 'prohibited', 'author_snapshot' => 'prohibited', 'case_snapshot' => 'prohibited', 'submitted_at' => 'prohibited', 'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited'];
    }

    public static function reviewRules(): array
    {
        return ['decision' => ['required', Rule::enum(ComplianceCaseInvestigationPlanDecision::class)], 'summary' => 'required|string|max:30000', 'id' => 'prohibited', 'compliance_case_investigation_plan_id' => 'prohibited', 'reviewed_by' => 'prohibited', 'reviewer_snapshot' => 'prohibited', 'plan_snapshot' => 'prohibited', 'reviewed_at' => 'prohibited', 'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited'];
    }
}
