<?php

namespace App\Http\Requests;

use App\Models\EnterpriseRiskScenario;
use Illuminate\Foundation\Http\FormRequest;

class ShowEnterpriseRiskScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scenario = $this->route('scenario');

        return $scenario instanceof EnterpriseRiskScenario && ($this->user()?->can('Manage Risk Portfolio') || $this->user()?->can('Read Risks') || $scenario->rootRisk->governanceProfile?->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return [
            'item_page' => ['sometimes', 'integer', 'min:1'],
            'item_per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
