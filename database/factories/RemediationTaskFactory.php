<?php

namespace Database\Factories;

use App\Models\AuditFinding;
use App\Models\RemediationProject;
use App\Models\RemediationTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RemediationTaskFactory extends Factory
{
    protected $model = RemediationTask::class;

    public function definition(): array
    {
        return [
            'remediation_project_id' => RemediationProject::factory(),
            'number' => 'RT-'.$this->faker->unique()->numerify('########'),
            'title' => $this->faker->sentence(6),
            'status' => 'Open',
            'priority' => 'Medium',
            'type' => 'Audit Finding',
            'owner_id' => User::factory(),
            'assignee_id' => User::factory(),
            'due_date' => now()->addMonth()->toDateString(),
            'weakness_description' => $this->faker->paragraph(),
            'audit_finding_id' => null,
        ];
    }

    public function forFinding(AuditFinding $finding): static
    {
        return $this->state(fn (): array => [
            'audit_item_id' => $finding->audit_item_id,
            'audit_finding_id' => $finding->id,
            'title' => $finding->code.' '.$finding->title,
            'weakness_description' => $finding->condition,
            'due_date' => $finding->latestResponse?->target_date,
        ]);
    }
}
