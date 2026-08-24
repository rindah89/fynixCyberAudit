<?php

namespace App\Http\Requests;

use App\Enums\IncidentPhase;
use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionIncidentPhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents')
            && $incident instanceof Incident
            && $this->user()?->can('update', $incident) === true;
    }

    public function rules(): array
    {
        return [
            'phase' => ['required', Rule::enum(IncidentPhase::class)],
            'summary' => 'required|string|max:10000',
            'from_phase' => 'prohibited', 'incident_snapshot' => 'prohibited',
            'transitioned_by' => 'prohibited', 'transitioned_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
