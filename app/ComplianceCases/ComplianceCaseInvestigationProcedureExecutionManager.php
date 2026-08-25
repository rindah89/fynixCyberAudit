<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
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
                ->orderBy('procedure_index')->lockForUpdate()->get();
            $plan = $plans->last();
            $review = $plan === null ? null : $reviews->get($plan->id);
            if ($plan === null || $review?->decision !== ComplianceCaseInvestigationPlanDecision::Approved) {
                throw ValidationException::withMessages(['plan' => 'Procedure execution requires the approved investigation plan.']);
            }
            $index = (int) $data['procedure_index'];
            if (! array_key_exists($index - 1, $plan->procedures)) {
                throw ValidationException::withMessages(['procedure_index' => 'The selected approved-plan procedure does not exist.']);
            }
            if ($existing->contains('procedure_index', $index)) {
                throw ValidationException::withMessages(['procedure_index' => 'This approved-plan procedure already has a retained execution.']);
            }
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $executedAt = now()->startOfSecond();
            $execution = new ComplianceCaseInvestigationProcedureExecution([
                'compliance_case_id' => $locked->id, 'compliance_case_investigation_plan_id' => $plan->id,
                'procedure_index' => $index, 'procedure_text' => $plan->procedures[$index - 1],
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

            return $execution->load(['executor:id,name,email', 'plan.review']);
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        $case = ComplianceCase::query()->findOrFail($case->id);
        abort_unless($actor->can('view', $case), 403);

        return $case->investigationProcedureExecutions()->with(['executor:id,name,email', 'plan.review'])->paginate($perPage);
    }

    public function payload(ComplianceCaseInvestigationProcedureExecution $execution): array
    {
        return [
            'compliance_case_id' => $execution->compliance_case_id,
            'compliance_case_investigation_plan_id' => $execution->compliance_case_investigation_plan_id,
            'procedure_index' => $execution->procedure_index, 'procedure_text' => $execution->procedure_text,
            'result' => $execution->result instanceof \BackedEnum ? $execution->result->value : $execution->result,
            'summary' => $execution->summary, 'findings' => $execution->findings,
            'source_reference' => $execution->source_reference, 'executed_by' => $execution->executed_by,
            'executor_snapshot' => $execution->executor_snapshot, 'plan_snapshot' => $execution->plan_snapshot,
            'case_snapshot' => $execution->case_snapshot, 'executed_at' => $execution->executed_at?->toIso8601String(),
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
            'plan_snapshot' => 'prohibited', 'case_snapshot' => 'prohibited', 'executed_at' => 'prohibited',
            'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }
}
