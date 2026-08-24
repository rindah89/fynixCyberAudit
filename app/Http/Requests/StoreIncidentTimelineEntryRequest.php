<?php

namespace App\Http\Requests;

use App\Enums\IncidentTimelineEntryType;
use App\Enums\IncidentTimelineVisibility;
use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentTimelineEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents') && $incident instanceof Incident && $this->user()?->can('update', $incident) === true;
    }

    public function rules(): array
    {
        return [
            'entry_type' => ['required', Rule::enum(IncidentTimelineEntryType::class)],
            'visibility' => ['required', Rule::enum(IncidentTimelineVisibility::class)],
            'occurred_at' => 'required|date|before_or_equal:now',
            'summary' => 'required|string|max:10000', 'details' => 'nullable|string|max:30000', 'pinned' => 'sometimes|boolean',
            'version' => 'prohibited', 'incident_snapshot' => 'prohibited', 'recorded_by' => 'prohibited',
            'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
