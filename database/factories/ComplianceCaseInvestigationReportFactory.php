<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\Enums\ComplianceCaseInvestigationReportOutcome;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\ComplianceCaseInvestigationReport;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInvestigationReportFactory extends Factory
{
    protected $model = ComplianceCaseInvestigationReport::class;

    public function definition(): array
    {
        return [
            'compliance_case_id' => fn (): int => ComplianceCaseInvestigationProcedureReview::factory()->create()->execution->compliance_case_id,
            'version' => 1, 'outcome' => ComplianceCaseInvestigationReportOutcome::Substantiated,
            'executive_summary' => 'Factory governed investigation report.',
            'analysis' => 'Factory synthesis of the retained approved procedure conclusion.',
            'findings' => 'Factory retained report findings.', 'recommendations' => 'Factory retained recommendation.',
            'report_snapshot' => [],
            'authored_by' => fn (array $attributes): int => (int) ComplianceCaseInvestigationProcedureReview::query()
                ->whereHas('execution', fn ($query) => $query->where('compliance_case_id', $attributes['compliance_case_id']))
                ->firstOrFail()->execution->executed_by,
            'author_snapshot' => [], 'authored_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseInvestigationReport $report): void {
            $report->loadMissing(['complianceCase', 'author']);
            $case = $report->complianceCase;
            $plan = $case->investigationPlans()->latest('version')->with('review')->firstOrFail();
            $executions = $case->investigationProcedureExecutions()->where('compliance_case_investigation_plan_id', $plan->id)->get()
                ->groupBy('procedure_index')->map->last()->sortKeys();
            $reviews = ComplianceCaseInvestigationProcedureReview::query()
                ->whereIn('compliance_case_investigation_procedure_execution_id', $executions->pluck('id'))->get()
                ->keyBy('compliance_case_investigation_procedure_execution_id');
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)->latest('version')->first();
            $manager = app(ComplianceCaseInvestigationReportManager::class);
            $report->report_snapshot = $manager->reportSnapshot($case, $event, $plan, $plan->review, $executions, $reviews);
            $report->author_snapshot = $report->author->only(['id', 'name', 'email']);
            $report->fingerprint = hash('sha256', CanonicalJson::encode($manager->reportPayload($report)));
        });
    }
}
