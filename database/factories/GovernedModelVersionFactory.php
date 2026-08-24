<?php

namespace Database\Factories;

use App\Models\GovernedModel;
use App\Models\GovernedModelVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GovernedModelVersionFactory extends Factory
{
    protected $model = GovernedModelVersion::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['governed_model_id' => GovernedModel::factory(), 'version' => 1, 'model_snapshot' => fn (array $a): array => self::snapshot(GovernedModel::findOrFail($a['governed_model_id'])), 'change_summary' => 'Factory-governed registration.', 'recorded_by' => User::factory(), 'recorded_at' => $at, 'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['governed_model_id' => $a['governed_model_id'], 'version' => $a['version'], 'model_snapshot' => $a['model_snapshot'], 'change_summary' => $a['change_summary'], 'recorded_by' => $a['recorded_by'], 'recorded_at' => $at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    public static function snapshot(GovernedModel $model): array
    {
        $model->load(['owner:id,name,email', 'developer:id,name,email']);
        $s = $model->only(['id', 'code', 'name', 'model_type', 'tier', 'lifecycle_status', 'intended_use', 'methodology', 'input_data', 'outputs', 'assumptions', 'limitations', 'usage_restrictions', 'implementation_reference', 'change_frequency', 'next_review_at', 'governed_at']) + ['owner' => $model->owner?->only(['id', 'name', 'email']), 'developer' => $model->developer?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
