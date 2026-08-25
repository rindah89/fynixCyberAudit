<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Models\ComplianceCaseIntake;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ComplianceCaseIntakeFactory extends Factory
{
    protected $model = ComplianceCaseIntake::class;

    public function definition(): array
    {
        $submittedAt = now()->startOfSecond();

        return [
            'reference' => 'CCI-FAC-'.Str::upper(Str::random(12)), 'title' => fake()->sentence(),
            'category' => ComplianceCaseCategory::PolicyViolation, 'priority' => ComplianceCasePriority::Medium,
            'allegation' => fake()->paragraph(), 'source_channel' => 'Authenticated employee portal',
            'source_reference' => null, 'confidential' => true, 'reporter_message' => fake()->sentence(),
            'submitted_by' => User::factory(), 'reporter_snapshot' => [],
            'submitted_at' => $submittedAt,
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseIntake $intake): void {
            $reporter = User::query()->withTrashed()->findOrFail($intake->submitted_by);
            $intake->reporter_snapshot = $reporter->only(['id', 'name', 'email']);
            $intake->fingerprint = hash('sha256', CanonicalJson::encode(app(ComplianceCaseIntakeManager::class)->submissionPayload($intake)));
        });
    }
}
