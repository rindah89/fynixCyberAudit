<?php

namespace Database\Factories;

use App\Models\Policy;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyAcknowledgementCampaignFactory extends Factory
{
    protected $model = PolicyAcknowledgementCampaign::class;

    public function definition(): array
    {
        return [
            'policy_id' => Policy::factory(),
            'version' => 1,
            'title' => fake()->sentence(4),
            'due_at' => now()->addMonth(),
            'launched_by' => User::factory(),
            'launched_at' => now(),
            'policy_snapshot' => fn (array $attributes): array => Policy::query()->findOrFail($attributes['policy_id'])
                ->only(['id', 'code', 'name', 'document_type', 'policy_scope', 'purpose', 'body', 'document_path', 'scope_id', 'department_id', 'status_id', 'owner_id', 'effective_date', 'retired_date', 'revision_history', 'updated_at']),
            'policy_fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($attributes['policy_snapshot'], JSON_THROW_ON_ERROR)),
        ];
    }
}
