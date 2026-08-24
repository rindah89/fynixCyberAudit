<?php

namespace Database\Factories;

use App\Models\AuditableEntity;
use App\Models\AuditableEntityAssessment;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class AuditableEntityAssessmentFactory extends Factory
{
    protected $model = AuditableEntityAssessment::class;

    public function definition(): array
    {
        return [
            'auditable_entity_id' => AuditableEntity::factory(), 'version' => 1,
            'inherent_likelihood' => 4, 'inherent_impact' => 4, 'inherent_score' => 16,
            'residual_likelihood' => 3, 'residual_impact' => 4, 'residual_score' => 12, 'priority_band' => 'medium',
            'rationale' => fake()->paragraph(),
            'entity_snapshot' => fn (array $attributes): array => AuditableEntity::query()->findOrFail($attributes['auditable_entity_id'])
                ->only(['id', 'code', 'name', 'description', 'entity_type', 'owner_id', 'criticality', 'status', 'assessment_frequency']),
            'risk_snapshots' => fn (array $attributes): array => AuditableEntity::query()->findOrFail($attributes['auditable_entity_id'])->risks
                ->map(fn (Risk $risk): array => $risk->only(['id', 'code', 'name', 'domain', 'status', 'inherent_risk', 'residual_risk', 'is_active', 'updated_at']))->all(),
            'control_snapshots' => fn (array $attributes): array => AuditableEntity::query()->findOrFail($attributes['auditable_entity_id'])->controls
                ->map(fn (Control $control): array => $control->only(['id', 'standard_id', 'control_owner_id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']))->all(),
            'next_assessment_at' => today()->addYear(),
            'governance_fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'entitySnapshot' => $attributes['entity_snapshot'], 'riskSnapshots' => $attributes['risk_snapshots'], 'controlSnapshots' => $attributes['control_snapshots'],
                'version' => $attributes['version'], 'inherentScore' => $attributes['inherent_score'], 'residualScore' => $attributes['residual_score'],
                'priorityBand' => $attributes['priority_band'], 'inherent_likelihood' => $attributes['inherent_likelihood'],
                'inherent_impact' => $attributes['inherent_impact'], 'residual_likelihood' => $attributes['residual_likelihood'],
                'residual_impact' => $attributes['residual_impact'], 'rationale' => $attributes['rationale'],
                'next_assessment_at' => Carbon::parse($attributes['next_assessment_at'])->toDateString(),
            ], JSON_THROW_ON_ERROR)),
            'assessed_by' => User::factory(), 'assessed_at' => now(),
        ];
    }
}
