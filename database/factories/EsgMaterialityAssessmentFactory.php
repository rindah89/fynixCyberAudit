<?php

namespace Database\Factories;

use App\Enums\EsgMaterialityDecision;
use App\Enums\EsgTopicStatus;
use App\Models\EsgMaterialityAssessment;
use App\Models\EsgMaterialTopicVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgMaterialityAssessment> */
class EsgMaterialityAssessmentFactory extends Factory
{
    public function definition(): array
    {
        $version = EsgMaterialTopicVersion::factory()->create();
        $assessedAt = now()->startOfSecond();
        $nextReviewAt = today()->addYear();
        $assessor = User::factory()->create();
        $payload = [
            'esg_material_topic_id' => $version->esg_material_topic_id,
            'version' => 1,
            'topic_version_id' => $version->id,
            'topic_snapshot' => $version->topic_snapshot,
            'impact_materiality' => 4,
            'financial_materiality' => 4,
            'stakeholder_evidence' => 'Factory stakeholder evidence.',
            'methodology' => 'Factory double-materiality methodology.',
            'decision' => EsgMaterialityDecision::Material->value,
            'decision_summary' => 'Factory independent materiality decision.',
            'assessed_by' => $assessor->id,
            'assessed_at' => $assessedAt->toIso8601String(),
            'next_review_at' => $nextReviewAt->toDateString(),
        ];

        return $payload + [
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (EsgMaterialityAssessment $assessment): void {
            $assessment->topic->update([
                'status' => EsgTopicStatus::Material,
                'next_review_at' => $assessment->next_review_at,
            ]);
        });
    }
}
