<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRecoveryExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'actual_recovery_time_minutes' => 'required|integer|min:0|max:525600',
            'actual_recovery_point_minutes' => 'required|integer|min:0|max:525600',
            'observations' => 'required|string|max:30000', 'evidence_reference' => 'nullable|string|max:255',
            'evidence_attachment_ids' => 'sometimes|array|max:20',
            'evidence_attachment_ids.*' => 'required|integer|distinct|exists:file_attachments,id',
            'outcome' => 'prohibited', 'rto_objective_minutes' => 'prohibited', 'rpo_objective_minutes' => 'prohibited',
        ];
    }
}
