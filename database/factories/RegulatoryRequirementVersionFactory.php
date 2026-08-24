<?php

namespace Database\Factories;

use App\Enums\RegulatoryChangeType;
use App\Enums\RegulatoryRequirementStatus;
use App\Models\Control;
use App\Models\Policy;
use App\Models\RegulatoryRequirement;
use App\Models\RegulatoryRequirementVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class RegulatoryRequirementVersionFactory extends Factory
{
    protected $model = RegulatoryRequirementVersion::class;

    public function definition(): array
    {
        return [
            'regulatory_requirement_id' => RegulatoryRequirement::factory(), 'version' => 1,
            'change_type' => RegulatoryChangeType::NewRequirement, 'status' => RegulatoryRequirementStatus::Active,
            'title' => fake()->sentence(5), 'requirement_text' => fake()->paragraph(), 'effective_at' => today(),
            'policy_ids' => [], 'control_ids' => [],
            'source_snapshot' => fn (array $attributes): array => RegulatoryRequirement::query()
                ->findOrFail($attributes['regulatory_requirement_id'])->source
                ->only(['id', 'code', 'title', 'authority', 'jurisdiction', 'reference_url', 'owner_id', 'status', 'updated_at']),
            'policy_snapshots' => function (array $attributes): array {
                return Policy::withTrashed()->whereKey($attributes['policy_ids'])->orderBy('id')->get()
                    ->map(fn ($policy): array => $policy->only(['id', 'code', 'name', 'owner_id', 'effective_date', 'retired_date', 'updated_at']))->all();
            },
            'control_snapshots' => function (array $attributes): array {
                return Control::withTrashed()->whereKey($attributes['control_ids'])->orderBy('id')->get()
                    ->map(fn ($control): array => $control->only(['id', 'standard_id', 'control_owner_id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']))->all();
            },
            'content_fingerprint' => function (array $attributes): string {
                $requirement = RegulatoryRequirement::query()->findOrFail($attributes['regulatory_requirement_id']);
                $payload = [
                    'requirement_id' => $requirement->id,
                    'requirement_code' => $requirement->code,
                    'requirement_owner_id' => $requirement->owner_id,
                    'version' => $attributes['version'],
                    'change_type' => $attributes['change_type'] instanceof RegulatoryChangeType ? $attributes['change_type']->value : $attributes['change_type'],
                    'status' => $attributes['status'] instanceof RegulatoryRequirementStatus ? $attributes['status']->value : $attributes['status'],
                    'title' => $attributes['title'],
                    'requirement_text' => $attributes['requirement_text'],
                    'effective_at' => Carbon::parse($attributes['effective_at'])->toDateString(),
                    'expires_at' => isset($attributes['expires_at']) ? Carbon::parse($attributes['expires_at'])->toDateString() : null,
                    'policy_ids' => $attributes['policy_ids'],
                    'control_ids' => $attributes['control_ids'],
                    'source_snapshot' => $attributes['source_snapshot'],
                    'policy_snapshots' => $attributes['policy_snapshots'],
                    'control_snapshots' => $attributes['control_snapshots'],
                ];

                return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            },
            'published_by' => User::factory(), 'published_at' => now(),
        ];
    }
}
