<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseClosureReportManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseInvestigationReportReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComplianceCaseClosureReportFactory extends Factory
{
    protected $model = ComplianceCaseClosureReport::class;

    public function definition(): array
    {
        $generatedAt = now()->startOfSecond();

        return [
            'compliance_case_id' => fn (): int => $this->closedGovernedCase()->id,
            'version' => 1,
            'report_snapshot' => fn (array $attributes): array => app(ComplianceCaseClosureReportManager::class)
                ->factorySnapshot(ComplianceCase::query()->findOrFail($attributes['compliance_case_id']), 'Factory governed closure report.'),
            'generated_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases'])),
            'generator_snapshot' => [], 'generated_at' => $generatedAt, 'report_disk' => 'private',
            'report_path' => fn (): string => 'compliance-case-closure-reports/'.Str::uuid().'.pdf',
            'report_size' => 1, 'report_sha256' => hash('sha256', 'x'), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseClosureReport $report): void {
            $report->loadMissing('generator');
            $report->generator_snapshot = $report->generator->only(['id', 'name', 'email']);
            $report->fingerprint = hash('sha256', CanonicalJson::encode(app(ComplianceCaseClosureReportManager::class)->payload($report)));
        })->afterCreating(function (ComplianceCaseClosureReport $report): void {
            Storage::disk($report->report_disk)->put($report->report_path, 'x');
        });
    }

    private function closedGovernedCase(): ComplianceCase
    {
        $case = ComplianceCaseInvestigationReportReview::factory()->create()->report->complianceCase;
        $resolver = User::factory()->create();
        $resolver->givePermissionTo('Manage Compliance Cases');
        app(ComplianceCaseManager::class)->record($resolver, $case, [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'Factory governed resolution.',
            'summary' => 'Resolve factory governed case.',
        ]);
        $closer = User::factory()->create();
        $closer->givePermissionTo('Manage Compliance Cases');
        app(ComplianceCaseManager::class)->record($closer, $case->refresh(), [
            'status' => ComplianceCaseStatus::Closed->value,
            'closure_summary' => 'Factory independent closure.',
            'summary' => 'Close factory governed case.',
        ]);

        return $case->refresh();
    }
}
