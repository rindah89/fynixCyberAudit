<?php

namespace Database\Factories;

use App\Enums\ModelValidationDecision;
use App\Models\GovernedModelVersion;
use App\Models\ModelValidationReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ModelValidationReviewFactory extends Factory
{
    protected $model = ModelValidationReview::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['model_version_id' => GovernedModelVersion::factory(), 'governed_model_id' => fn (array $a): int => GovernedModelVersion::findOrFail($a['model_version_id'])->governed_model_id, 'version' => 1, 'model_snapshot' => fn (array $a): array => GovernedModelVersion::findOrFail($a['model_version_id'])->model_snapshot, 'scope' => 'Independent validation scope.', 'testing_performed' => 'Reperformance and benchmark comparison.', 'findings' => ['No material exception identified'], 'performance_summary' => 'Performance remained within deliberate tolerances.', 'limitations_assessment' => 'Retained limitations remain relevant.', 'decision' => ModelValidationDecision::Approved, 'conditions' => [], 'decision_summary' => 'Approved for the exact retained version.', 'validated_by' => User::factory(), 'validated_at' => $at, 'valid_until' => today()->addYear(), 'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['governed_model_id' => $a['governed_model_id'], 'version' => $a['version'], 'model_version_id' => $a['model_version_id'], 'model_snapshot' => $a['model_snapshot'], 'scope' => $a['scope'], 'testing_performed' => $a['testing_performed'], 'findings' => $a['findings'], 'performance_summary' => $a['performance_summary'], 'limitations_assessment' => $a['limitations_assessment'], 'decision' => $a['decision'] instanceof ModelValidationDecision ? $a['decision']->value : $a['decision'], 'conditions' => $a['conditions'], 'decision_summary' => $a['decision_summary'], 'validated_by' => $a['validated_by'], 'validated_at' => $at->toIso8601String(), 'valid_until' => Carbon::parse($a['valid_until'])->toDateString()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }
}
