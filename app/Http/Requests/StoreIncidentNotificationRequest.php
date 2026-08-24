<?php

namespace App\Http\Requests;

use App\Incidents\IncidentNotificationManager;
use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('incidents') && $this->route('incident') instanceof Incident
            && $this->user()?->can('Manage Breach Notifications') === true;
    }

    public function rules(): array
    {
        return IncidentNotificationManager::registerRules() + [
            'incident_id' => 'prohibited', 'status' => 'prohibited', 'sent_at' => 'prohibited',
            'governed_at' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
