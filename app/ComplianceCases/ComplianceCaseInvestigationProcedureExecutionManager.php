<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCaseInvestigationProcedureReviewDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseInvestigationProcedureExecutionManager
{
    public function record(User $actor, ComplianceCase $case, array $data): ComplianceCaseInvestigationProcedureExecution
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseInvestigationProcedureExecution {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $isManager = $actor->can('Manage Compliance Cases');
            $isInvestigator = $actor->can('Investigate Compliance Cases') && $locked->assigned_to === $actor->id;
            abort_unless($isManager || $isInvestigator, 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            if (! in_array($locked->status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)) {
                throw ValidationException::withMessages(['case' => 'Investigation procedures may be concluded only during investigation or action-required work.']);
            }
            foreach (['summary', 'findings', 'source_reference'] as $field) {
                if (isset($data[$field]) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]);
                }
            }
            $data = Validator::make($data, self::rules())->validate();
            if ($data['result'] === ComplianceCaseInvestigationProcedureResult::ExceptionIdentified->value && blank($data['findings'] ?? null)) {
                throw ValidationException::withMessages(['findings' => 'An exception-identified conclusion requires retained findings.']);
            }
            $events = ComplianceCaseEvent::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            $plans = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            $reviews = ComplianceCaseInvestigationPlanReview::query()->whereIn('compliance_case_investigation_plan_id', $plans->pluck('id'))
                ->orderBy('compliance_case_investigation_plan_id')->lockForUpdate()->get()->keyBy('compliance_case_investigation_plan_id');
            $existing = ComplianceCaseInvestigationProcedureExecution::query()->where('compliance_case_id', $locked->id)
                ->orderBy('procedure_index')->orderBy('version')->lockForUpdate()->get();
            $existingReviews = ComplianceCaseInvestigationProcedureReview::query()
                ->whereIn('compliance_case_investigation_procedure_execution_id', $existing->pluck('id'))
                ->orderBy('compliance_case_investigation_procedure_execution_id')->lockForUpdate()->get()
                ->keyBy('compliance_case_investigation_procedure_execution_id');
            $plan = $plans->last();
            $review = $plan === null ? null : $reviews->get($plan->id);
            if ($plan === null || $review?->decision !== ComplianceCaseInvestigationPlanDecision::Approved) {
                throw ValidationException::withMessages(['plan' => 'Procedure execution requires the approved investigation plan.']);
            }
            $index = (int) $data['procedure_index'];
            if (! array_key_exists($index - 1, $plan->procedures)) {
                throw ValidationException::withMessages(['procedure_index' => 'The selected approved-plan procedure does not exist.']);
            }
            $prior = $existing->where('compliance_case_investigation_plan_id', $plan->id)->where('procedure_index', $index)->last();
            if ($prior !== null && $existingReviews->get($prior->id)?->decision !== ComplianceCaseInvestigationProcedureReviewDecision::ReworkRequired) {
                throw ValidationException::withMessages(['procedure_index' => 'A later conclusion requires a rework-required review of the latest retained version.']);
            }
            if (($prior?->version ?? 0) >= 20) {
                throw ValidationException::withMessages(['procedure_index' => 'A governed procedure is limited to 20 retained conclusion versions.']);
            }
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $executedAt = now()->startOfSecond();
            $execution = new ComplianceCaseInvestigationProcedureExecution([
                'compliance_case_id' => $locked->id, 'compliance_case_investigation_plan_id' => $plan->id,
                'procedure_index' => $index, 'version' => ($prior?->version ?? 0) + 1,
                'fingerprint_version' => 'procedure-execution/v2', 'procedure_text' => $plan->procedures[$index - 1],
                'result' => ComplianceCaseInvestigationProcedureResult::from($data['result']),
                'summary' => $data['summary'], 'findings' => $data['findings'] ?? null,
                'source_reference' => $data['source_reference'] ?? null, 'executed_by' => $actor->id,
                'executor_snapshot' => $actor->only(['id', 'name', 'email']),
                'plan_snapshot' => ['id' => $plan->id] + app(ComplianceCaseInvestigationPlanManager::class)->planPayload($plan) + [
                    'fingerprint' => $plan->fingerprint,
                    'review' => ['id' => $review->id] + app(ComplianceCaseInvestigationPlanManager::class)->reviewPayload($review) + ['fingerprint' => $review->fingerprint],
                ],
                'case_snapshot' => app(ComplianceCaseInvestigationPlanManager::class)->caseSnapshot($locked, $events->last()),
                'executed_at' => $executedAt,
            ]);
            $execution->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($execution)));
            $execution->save();

            return $execution->load(['executor:id,name,email', 'plan.review', 'review.reviewer:id,name,email']);
        }, 3);
    }

    public function review(User $actor, ComplianceCaseInvestigationProcedureExecution $execution, array $data): ComplianceCaseInvestigationProcedureReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $execution, $data): ComplianceCaseInvestigationProcedureReview {
            $caseId = ComplianceCaseInvestigationProcedureExecution::query()->whereKey($execution->id)->value('compliance_case_id');
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($caseId);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            abort_unless($actor->can('Manage Compliance Cases'), 403);
            if (! in_array($case->status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)) {
                throw ValidationException::withMessages(['case' => 'Procedure conclusions may be reviewed only during investigation or action-required work.']);
            }
            $executions = ComplianceCaseInvestigationProcedureExecution::query()->where('compliance_case_id', $case->id)
                ->orderBy('procedure_index')->orderBy('version')->lockForUpdate()->get();
            $locked = $executions->firstWhere('id', $execution->id) ?? throw ValidationException::withMessages(['execution' => 'The selected execution is not contained by this case.']);
            abort_if($locked->executed_by === $actor->id, 403, 'The procedure executor cannot review their own conclusion.');
            $reviews = ComplianceCaseInvestigationProcedureReview::query()
                ->whereIn('compliance_case_investigation_procedure_execution_id', $executions->pluck('id'))
                ->orderBy('compliance_case_investigation_procedure_execution_id')->lockForUpdate()->get();
            if ($reviews->contains('compliance_case_investigation_procedure_execution_id', $locked->id)) {
                throw ValidationException::withMessages(['execution' => 'This procedure conclusion already has a retained supervisory review.']);
            }
            $latest = $executions->where('compliance_case_investigation_plan_id', $locked->compliance_case_investigation_plan_id)
                ->where('procedure_index', $locked->procedure_index)->last();
            if ($latest?->id !== $locked->id) {
                throw ValidationException::withMessages(['execution' => 'Only the latest procedure conclusion version may be reviewed.']);
            }
            foreach (['summary'] as $field) {
                if (isset($data[$field]) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]);
                }
            }
            $data = Validator::make($data, self::reviewRules())->validate();
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $reviewedAt = now()->startOfSecond();
            $review = new ComplianceCaseInvestigationProcedureReview([
                'compliance_case_investigation_procedure_execution_id' => $locked->id,
                'decision' => ComplianceCaseInvestigationProcedureReviewDecision::from($data['decision']),
                'summary' => $data['summary'], 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']),
                'execution_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->payload($locked),
                'reviewed_at' => $reviewedAt,
            ]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->reviewPayload($review)));
            $review->save();

            return $review->load(['reviewer:id,name,email', 'execution.executor:id,name,email']);
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        $case = ComplianceCase::query()->findOrFail($case->id);
        abort_unless($actor->can('view', $case), 403);

        return $case->investigationProcedureExecutions()->with(['executor:id,name,email', 'plan.review', 'review.reviewer:id,name,email'])->paginate($perPage);
    }

    public function payload(ComplianceCaseInvestigationProcedureExecution $execution): array
    {
        $payload = [
            'compliance_case_id' => $execution->compliance_case_id,
            'compliance_case_investigation_plan_id' => $execution->compliance_case_investigation_plan_id,
            'procedure_index' => $execution->procedure_index, 'procedure_text' => $execution->procedure_text,
            'result' => $execution->result instanceof \BackedEnum ? $execution->result->value : $execution->result,
            'summary' => $execution->summary, 'findings' => $execution->findings,
            'source_reference' => $execution->source_reference, 'executed_by' => $execution->executed_by,
            'executor_snapshot' => $execution->executor_snapshot, 'plan_snapshot' => $execution->plan_snapshot,
            'case_snapshot' => $execution->case_snapshot, 'executed_at' => $execution->executed_at?->toIso8601String(),
        ];

        if ($execution->fingerprint_version === 'procedure-execution/v2') {
            $payload = ['fingerprint_version' => $execution->fingerprint_version, 'version' => $execution->version] + $payload;
        }

        return $payload;
    }

    public function reviewPayload(ComplianceCaseInvestigationProcedureReview $review): array
    {
        return [
            'compliance_case_investigation_procedure_execution_id' => $review->compliance_case_investigation_procedure_execution_id,
            'decision' => $review->decision instanceof \BackedEnum ? $review->decision->value : $review->decision,
            'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by,
            'reviewer_snapshot' => $review->reviewer_snapshot, 'execution_snapshot' => $review->execution_snapshot,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }

    public static function rules(): array
    {
        return [
            'procedure_index' => 'required|integer|min:1|max:50',
            'result' => ['required', Rule::enum(ComplianceCaseInvestigationProcedureResult::class)],
            'summary' => 'required|string|max:30000', 'findings' => 'nullable|string|max:30000',
            'source_reference' => 'nullable|string|max:2000',
            'id' => 'prohibited', 'compliance_case_id' => 'prohibited', 'compliance_case_investigation_plan_id' => 'prohibited',
            'procedure_text' => 'prohibited', 'executed_by' => 'prohibited', 'executor_snapshot' => 'prohibited',
            'version' => 'prohibited', 'fingerprint_version' => 'prohibited',
            'plan_snapshot' => 'prohibited', 'case_snapshot' => 'prohibited', 'executed_at' => 'prohibited',
            'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }

    public static function reviewRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ComplianceCaseInvestigationProcedureReviewDecision::class)],
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_investigation_procedure_execution_id' => 'prohibited',
            'reviewed_by' => 'prohibited', 'reviewer_snapshot' => 'prohibited', 'execution_snapshot' => 'prohibited',
            'reviewed_at' => 'prohibited', 'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }
}
