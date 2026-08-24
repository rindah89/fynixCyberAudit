<?php

namespace Database\Factories;

use App\Enums\PrivacyAssessmentDecision;
use App\Models\PrivacyActivityVersion;
use App\Models\PrivacyImpactAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class PrivacyImpactAssessmentFactory extends Factory
{
    protected $model = PrivacyImpactAssessment::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['activity_version_id' => PrivacyActivityVersion::factory(),
            'privacy_processing_activity_id' => fn (array $a): int => PrivacyActivityVersion::query()->findOrFail($a['activity_version_id'])->privacy_processing_activity_id,
            'version' => 1, 'activity_snapshot' => fn (array $a): array => PrivacyActivityVersion::query()->findOrFail($a['activity_version_id'])->activity_snapshot,
            'necessity_assessment' => 'The stated purpose requires the bounded processing.', 'proportionality_assessment' => 'Collection is limited to the stated categories.', 'risk_summary' => 'Unauthorized disclosure is the principal deliberate risk.', 'mitigations' => ['Role-based access'], 'residual_risk' => 'Low', 'decision' => PrivacyAssessmentDecision::Approved, 'decision_summary' => 'Approved for the retained context.', 'assessed_by' => User::factory(), 'assessed_at' => $at, 'next_review_at' => today()->addYear(),
            'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['privacy_processing_activity_id' => $a['privacy_processing_activity_id'], 'version' => $a['version'], 'activity_version_id' => $a['activity_version_id'], 'activity_snapshot' => $a['activity_snapshot'], 'necessity_assessment' => $a['necessity_assessment'], 'proportionality_assessment' => $a['proportionality_assessment'], 'risk_summary' => $a['risk_summary'], 'mitigations' => $a['mitigations'], 'residual_risk' => $a['residual_risk'], 'decision' => $a['decision'] instanceof PrivacyAssessmentDecision ? $a['decision']->value : $a['decision'], 'decision_summary' => $a['decision_summary'], 'next_review_at' => Carbon::parse($a['next_review_at'])->toDateString(), 'assessed_by' => $a['assessed_by'], 'assessed_at' => $at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }
}
