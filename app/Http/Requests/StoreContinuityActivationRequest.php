<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreContinuityActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'status' => 'prohibited', 'activated_by' => 'prohibited', 'service_snapshot' => 'prohibited', 'plan_snapshot' => 'prohibited',
            'incident_id' => 'nullable|integer|exists:incidents,id',
            'disruption_summary' => 'required|string|max:10000', 'business_impact' => 'required|string|max:30000',
            'started_at' => 'required|date|before_or_equal:now',
        ];
    }
}
