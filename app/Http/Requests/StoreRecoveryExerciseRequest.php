<?php

namespace App\Http\Requests;

use App\Models\RecoveryPlan;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRecoveryExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return ['scenario' => 'required|string|max:20000', 'scheduled_at' => 'required|date', 'incident_id' => 'nullable|exists:incidents,id'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $plan = $this->route('plan');
            if ($plan instanceof RecoveryPlan && $plan->status->value !== 'approved') {
                $validator->errors()->add('recovery_plan_id', 'Only approved plans can be exercised.');
            }
        }];
    }
}
