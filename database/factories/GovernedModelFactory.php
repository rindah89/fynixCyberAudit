<?php

namespace Database\Factories;

use App\Enums\ModelGovernanceStatus;
use App\Enums\ModelLifecycleStatus;
use App\Models\GovernedModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GovernedModelFactory extends Factory
{
    protected $model = GovernedModel::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['code' => 'MDL-FAC-'.Str::upper(Str::random(10)), 'name' => fake()->sentence(4), 'model_type' => 'Statistical', 'tier' => 2, 'lifecycle_status' => ModelLifecycleStatus::Proposed, 'governance_status' => ModelGovernanceStatus::ValidationRequired, 'owner_id' => User::factory(), 'developer_id' => User::factory(), 'intended_use' => 'Governed portfolio decision support.', 'methodology' => 'Documented statistical methodology.', 'input_data' => ['Governed source data'], 'outputs' => ['Model estimate'], 'assumptions' => ['Historical relationship remains relevant'], 'limitations' => ['Structural change may reduce performance'], 'usage_restrictions' => ['No individual automated decisions'], 'implementation_reference' => null, 'change_frequency' => 'Annual or material change', 'next_review_at' => today()->addYear(), 'governed_at' => $at];
    }
}
