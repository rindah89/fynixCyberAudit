<?php

namespace App\Http\Requests;

use App\Enums\IncidentAffectedEntityType;
use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentAffectedEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents') && $incident instanceof Incident && $this->user()?->can('update', $incident) === true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::enum(IncidentAffectedEntityType::class)],
            'entity_id' => 'required|integer|min:1', 'impact_summary' => 'required|string|max:30000',
            'control_failure_note' => 'nullable|string|max:30000',
            'entity_id_snapshot' => 'prohibited', 'entity_snapshot' => 'prohibited', 'linked_by' => 'prohibited',
            'linked_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
