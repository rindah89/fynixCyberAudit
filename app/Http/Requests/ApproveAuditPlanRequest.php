<?php

namespace App\Http\Requests;

use App\Models\AuditPlan;
use Illuminate\Foundation\Http\FormRequest;

class ApproveAuditPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof AuditPlan && ($this->user()?->can('Update Programs') || $plan->manager_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'], 'approved_by' => ['prohibited'], 'approved_at' => ['prohibited'],
            'approval_snapshot' => ['prohibited'], 'approval_fingerprint' => ['prohibited'],
        ];
    }
}
