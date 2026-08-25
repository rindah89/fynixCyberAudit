<?php

namespace Database\Factories;

use App\Enums\ComplianceCaseInterviewStatus;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseInterview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInterviewFactory extends Factory
{
    protected $model = ComplianceCaseInterview::class;

    public function definition(): array
    {
        return [
            'compliance_case_id' => fn (): int => ComplianceCase::factory()->create(['status' => ComplianceCaseStatus::Investigating])->id,
            'subject_user_id' => User::factory(), 'subject_reference' => null,
            'interviewer_id' => function (): int {
                $interviewer = User::factory()->create();
                $interviewer->givePermissionTo('Investigate Compliance Cases');

                return $interviewer->id;
            },
            'status' => ComplianceCaseInterviewStatus::Scheduled,
            'scheduled_at' => now()->addDay()->startOfSecond(), 'conducted_at' => null,
            'location' => 'Secure meeting room', 'purpose' => 'Gather deliberate witness context.',
            'summary' => null, 'cancellation_reason' => null,
        ];
    }
}
