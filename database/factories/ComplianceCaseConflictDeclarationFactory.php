<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Models\ComplianceCaseEvent;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseConflictDeclaration> */
class ComplianceCaseConflictDeclarationFactory extends Factory
{
    protected $model = ComplianceCaseConflictDeclaration::class;

    public function definition(): array
    {
        $declarer = User::factory()->create();
        $declarer->assignRole('Security Admin');
        $subject = User::factory()->create();
        $case = ComplianceCase::factory()->create(['opened_by' => $declarer->id]);
        $event = ComplianceCaseEvent::factory()->create(['compliance_case_id' => $case->id, 'recorded_by' => $declarer->id]);
        $declaredAt = now()->startOfSecond();

        return [
            'compliance_case_id' => $case->id, 'compliance_case_event_id' => $event->id, 'version' => 1,
            'subject_user_id' => $subject->id, 'subject_snapshot' => $subject->only(['id', 'name', 'email']),
            'declared_by' => $declarer->id, 'declarer_snapshot' => $declarer->only(['id', 'name', 'email']),
            'nature' => 'Factory declared personal relationship.',
            'rationale' => 'Factory independence concern.',
            'case_snapshot' => ['case' => ['id' => $case->id, 'number' => $case->number], 'event' => ['id' => $event->id, 'fingerprint' => $event->fingerprint]],
            'latest_event_snapshot' => ['id' => $event->id, 'version' => $event->version, 'fingerprint' => $event->fingerprint],
            'declared_at' => $declaredAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseConflictDeclaration $declaration): void {
            $declaration->fingerprint = hash('sha256', CanonicalJson::encode(app(ComplianceCaseConflictManager::class)->declarationPayload($declaration)));
        });
    }
}
