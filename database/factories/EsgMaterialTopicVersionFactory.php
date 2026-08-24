<?php

namespace Database\Factories;

use App\Models\EsgMaterialTopic;
use App\Models\EsgMaterialTopicVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EsgMaterialTopicVersion> */
class EsgMaterialTopicVersionFactory extends Factory
{
    public function definition(): array
    {
        $recordedAt = now()->startOfSecond();

        return [
            'esg_material_topic_id' => EsgMaterialTopic::factory(),
            'version' => 1,
            'topic_snapshot' => fn (array $attributes): array => self::topicSnapshot(EsgMaterialTopic::query()->findOrFail($attributes['esg_material_topic_id'])),
            'change_summary' => 'Factory governed topic version.',
            'recorded_by' => fn (array $attributes): int => (int) EsgMaterialTopic::query()->findOrFail($attributes['esg_material_topic_id'])->owner_id,
            'recorded_at' => $recordedAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'esg_material_topic_id' => $attributes['esg_material_topic_id'],
                'version' => $attributes['version'],
                'topic_snapshot' => $attributes['topic_snapshot'],
                'change_summary' => $attributes['change_summary'],
                'recorded_by' => $attributes['recorded_by'],
                'recorded_at' => $recordedAt->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    public static function topicSnapshot(EsgMaterialTopic $topic): array
    {
        $topic->load('owner:id,name,email');
        $snapshot = $topic->only([
            'id', 'code', 'name', 'pillar', 'status', 'description', 'impact_context', 'risk_context',
            'opportunity_context', 'stakeholder_groups', 'framework_references', 'organizational_boundary',
            'source_reference', 'next_review_at', 'governed_at',
        ]) + ['owner' => $topic->owner?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
