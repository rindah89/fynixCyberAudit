<?php

namespace App\Http\Requests;

use App\Enums\IncidentTaskStatus;
use App\Models\IncidentTask;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordIncidentTaskEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        $actor = $this->user();

        return Enterprise::enabled('incidents') && $task instanceof IncidentTask && $actor !== null
            && ($actor->can('update', $task->incident) || $actor->can('Manage Incident Tasks') || $task->assignee_id === $actor->id);
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(IncidentTaskStatus::class)],
            'assignee_id' => 'sometimes|nullable|integer|exists:users,id',
            'due_date' => 'sometimes|nullable|date|after_or_equal:today',
            'evidence_attachment_ids' => 'sometimes|array|max:20',
            'evidence_attachment_ids.*' => 'integer|distinct',
            'summary' => 'required|string|max:10000',
            'incident_id' => 'prohibited', 'incident_task_id' => 'prohibited', 'version' => 'prohibited',
            'event_type' => 'prohibited', 'before_snapshot' => 'prohibited', 'after_snapshot' => 'prohibited',
            'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
            'evidence_manifest' => 'prohibited',
        ];
    }
}
