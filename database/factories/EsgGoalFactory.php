<?php

namespace Database\Factories;

use App\Enums\EsgGoalStatus;
use App\Models\EsgGoal;
use App\Models\EsgMaterialityAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgGoal> */
class EsgGoalFactory extends Factory
{
    public function definition(): array
    {
        $assessment = EsgMaterialityAssessment::factory()->create();
        $topic = $assessment->topic->fresh();
        $governedAt = now()->startOfSecond();
        $payload = [
            'esg_material_topic_id' => $topic->id,
            'code' => $topic->code.'-G001',
            'title' => 'Factory ESG performance goal',
            'description' => 'Factory governed ESG performance goal.',
            'owner_id' => $topic->owner_id,
            'baseline_date' => today()->subYear()->toDateString(),
            'target_date' => today()->addYears(3)->toDateString(),
            'topic_snapshot' => $assessment->topic_snapshot,
            'assessment_snapshot' => self::assessmentSnapshot($assessment),
            'created_by' => $topic->owner_id,
            'governed_at' => $governedAt->toIso8601String(),
        ];

        return $payload + ['status' => EsgGoalStatus::Active, 'fingerprint' => self::fingerprint($payload)];
    }

    private static function assessmentSnapshot(EsgMaterialityAssessment $assessment): array
    {
        return json_decode(json_encode($assessment->only(['id', 'version', 'topic_version_id', 'topic_snapshot', 'impact_materiality', 'financial_materiality', 'stakeholder_evidence', 'methodology', 'decision', 'decision_summary', 'assessed_by', 'assessed_at', 'next_review_at', 'fingerprint']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
