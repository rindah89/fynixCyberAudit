<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('incidents') && $this->user()?->can('create', Incident::class) === true;
    }

    public function rules(): array
    {
        return [
            'incident_playbook_id' => 'required|integer|exists:incident_playbooks,id',
            'title' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'severity' => 'required|in:Low,Medium,High,Critical',
            'detected_at' => 'required|date|before_or_equal:now',
            'involves_data' => 'sometimes|boolean',
            'involves_pii' => 'sometimes|boolean',
            'is_breach' => 'sometimes|boolean',
            'number' => 'prohibited', 'status' => 'prohibited', 'phase' => 'prohibited',
            'lead_id' => 'prohibited', 'reporter_id' => 'prohibited',
            'phase_timestamps' => 'prohibited', 'playbook_snapshot' => 'prohibited',
            'governed_at' => 'prohibited',
        ];
    }
}
