<?php

namespace Database\Factories;

use App\Models\ControlTestDefinition;
use App\Models\ControlTestExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ControlTestExecutionFactory extends Factory
{
    protected $model = ControlTestExecution::class;

    public function definition(): array
    {
        return [
            'control_test_definition_id' => ControlTestDefinition::factory(),
            'executed_by' => User::factory(),
            'observed_value' => '10',
            'metric_type' => 'numeric',
            'operator' => 'greater_than_or_equal',
            'expected_value' => '10',
            'outcome' => 'passed',
            'result_reason' => 'Observed value met the expected threshold.',
            'executed_at' => now(),
        ];
    }
}
