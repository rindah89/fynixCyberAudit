<?php

namespace Database\Factories;

use App\Models\PrivacyActivityVersion;
use App\Models\PrivacyProcessingActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrivacyActivityVersionFactory extends Factory
{
    protected $model = PrivacyActivityVersion::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['privacy_processing_activity_id' => PrivacyProcessingActivity::factory(), 'version' => 1,
            'activity_snapshot' => fn (array $a): array => self::snapshot(PrivacyProcessingActivity::query()->findOrFail($a['privacy_processing_activity_id'])),
            'change_summary' => 'Factory-governed registration.', 'recorded_by' => User::factory(), 'recorded_at' => $at,
            'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['privacy_processing_activity_id' => $a['privacy_processing_activity_id'], 'version' => $a['version'], 'activity_snapshot' => $a['activity_snapshot'], 'change_summary' => $a['change_summary'], 'recorded_by' => $a['recorded_by'], 'recorded_at' => $at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    public static function snapshot(PrivacyProcessingActivity $activity): array
    {
        $activity->load('owner:id,name,email');
        $snapshot = $activity->only(['id', 'number', 'name', 'status', 'purpose', 'lawful_basis', 'data_subject_categories', 'personal_data_categories', 'special_category_data', 'recipient_categories', 'systems_and_vendors', 'processing_locations', 'cross_border_transfer', 'transfer_safeguards', 'retention_period', 'security_measures', 'source_reference', 'next_review_at', 'governed_at']) + ['owner' => $activity->owner?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
