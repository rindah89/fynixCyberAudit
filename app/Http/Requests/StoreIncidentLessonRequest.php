<?php

namespace App\Http\Requests;

use App\Incidents\IncidentLessonManager;
use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents') && $incident instanceof Incident
            && ($this->user()?->can('update', $incident) === true || $this->user()?->can('Manage Incidents') === true);
    }

    public function rules(): array
    {
        return IncidentLessonManager::registerRules() + [
            'incident_id' => 'prohibited', 'status' => 'prohibited', 'governed_at' => 'prohibited',
            'version' => 'prohibited', 'before_snapshot' => 'prohibited', 'after_snapshot' => 'prohibited',
            'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
