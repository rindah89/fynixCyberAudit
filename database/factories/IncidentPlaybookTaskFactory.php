<?php

namespace Database\Factories;

use App\Enums\IncidentPhase;
use App\Models\IncidentPlaybook;
use App\Models\IncidentPlaybookTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentPlaybookTask>
 */
class IncidentPlaybookTaskFactory extends Factory
{
    protected $model = IncidentPlaybookTask::class;

    public function definition(): array
    {
        return [
            'incident_playbook_id' => IncidentPlaybook::factory(),
            'title' => $this->faker->sentence(4),
            'phase' => $this->faker->randomElement(IncidentPhase::cases()),
            'priority' => 'Medium',
            'sort_order' => 0,
        ];
    }
}
