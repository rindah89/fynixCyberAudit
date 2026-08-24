<?php

namespace App\Http\Requests;

use App\Incidents\IncidentNotificationManager;
use App\Models\IncidentNotification;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class RecordIncidentNotificationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('incidents') && $this->route('notification') instanceof IncidentNotification
            && $this->user()?->can('Manage Breach Notifications') === true;
    }

    public function rules(): array
    {
        return IncidentNotificationManager::decisionRules() + [
            'incident_id' => 'prohibited', 'incident_notification_id' => 'prohibited',
            'sent_at' => 'prohibited', 'version' => 'prohibited', 'before_snapshot' => 'prohibited',
            'after_snapshot' => 'prohibited', 'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited',
            'fingerprint' => 'prohibited',
        ];
    }
}
