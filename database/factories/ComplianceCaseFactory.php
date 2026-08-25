<?php

namespace Database\Factories;

use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ComplianceCaseFactory extends Factory
{
    protected $model = ComplianceCase::class;

    public function definition(): array
    {
        $openedAt = now()->startOfSecond();

        return [
            'number' => 'CC-FAC-'.Str::upper(Str::random(12)), 'title' => fake()->sentence(),
            'category' => ComplianceCaseCategory::PolicyViolation, 'priority' => ComplianceCasePriority::Medium,
            'status' => ComplianceCaseStatus::New, 'allegation' => fake()->paragraph(),
            'source_channel' => 'Operator intake', 'source_reference' => null, 'reporter_reference' => null,
            'confidential' => true, 'opened_by' => User::factory(), 'assigned_to' => null, 'due_at' => null,
            'triage_summary' => null, 'investigation_summary' => null, 'resolution_summary' => null, 'closure_summary' => null,
            'opened_at' => $openedAt, 'resolved_at' => null, 'closed_at' => null, 'governed_at' => $openedAt,
            'investigation_planning_governed_at' => $openedAt,
        ];
    }
}
