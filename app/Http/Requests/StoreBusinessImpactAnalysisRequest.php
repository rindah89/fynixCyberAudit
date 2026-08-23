<?php

namespace App\Http\Requests;

use App\Enums\ResilienceCriticality;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBusinessImpactAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'version' => 'prohibited',
            'maximum_tolerable_downtime_minutes' => 'required|integer|min:1|max:525600',
            'recovery_time_objective_minutes' => 'required|integer|min:1|max:525600',
            'recovery_point_objective_minutes' => 'required|integer|min:0|max:525600',
            'operational_impact' => ['required', Rule::enum(ResilienceCriticality::class)],
            'regulatory_impact' => ['nullable', Rule::enum(ResilienceCriticality::class)],
            'reputational_impact' => ['nullable', Rule::enum(ResilienceCriticality::class)],
            'financial_impact_per_hour' => 'nullable|decimal:0,2|min:0|max:9999999999999.99',
            'rationale' => 'required|string|max:10000', 'approve' => 'sometimes|boolean',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->integer('recovery_time_objective_minutes') > $this->integer('maximum_tolerable_downtime_minutes')) {
                $validator->errors()->add('recovery_time_objective_minutes', 'RTO cannot exceed maximum tolerable downtime.');
            }
        }];
    }
}
