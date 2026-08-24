<?php

namespace App\Http\Requests;

use App\Enums\ContinuityActivationStatus;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionContinuityActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'version' => 'prohibited', 'fingerprint' => 'prohibited', 'recorded_by' => 'prohibited', 'activation_snapshot' => 'prohibited',
            'status' => ['required', Rule::enum(ContinuityActivationStatus::class)],
            'summary' => 'required|string|max:10000',
            'actual_recovery_point_minutes' => 'required_if:status,restored|nullable|integer|min:0|max:525600',
        ];
    }
}
