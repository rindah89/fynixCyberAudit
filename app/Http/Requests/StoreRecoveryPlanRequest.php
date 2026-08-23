<?php

namespace App\Http\Requests;

use App\Models\BusinessService;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRecoveryPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'version' => 'prohibited',
            'title' => 'required|string|max:255', 'owner_id' => 'required|exists:users,id', 'strategy' => 'required|string|max:20000',
            'activation_criteria' => 'required|string|max:10000', 'recovery_procedure' => 'required|string|max:30000',
            'communication_plan' => 'required|string|max:20000', 'review_due_at' => 'required|date', 'approve' => 'sometimes|boolean',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $service = $this->route('service');
            if ($this->boolean('approve') && $service instanceof BusinessService && ! $service->latestApprovedImpactAnalysis()->exists()) {
                $validator->errors()->add('approve', 'An approved impact analysis is required before approving a recovery plan.');
            }
            if ($this->boolean('approve') && $this->date('review_due_at')?->isBefore(today())) {
                $validator->errors()->add('review_due_at', 'An approved recovery plan requires a current or future review date.');
            }
        }];
    }
}
