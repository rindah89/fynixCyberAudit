<?php

namespace App\Http\Requests;

use App\Incidents\IncidentLessonManager;
use App\Models\IncidentLesson;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class RecordIncidentLessonProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');
        $actor = $this->user();

        return Enterprise::enabled('incidents') && $lesson instanceof IncidentLesson && $actor !== null
            && ($actor->can('update', $lesson->incident) || $actor->can('Manage Incidents') || $lesson->owner_id === $actor->id);
    }

    public function rules(): array
    {
        return IncidentLessonManager::progressRules() + [
            'incident_id' => 'prohibited', 'incident_lesson_id' => 'prohibited', 'governed_at' => 'prohibited',
            'version' => 'prohibited', 'before_snapshot' => 'prohibited', 'after_snapshot' => 'prohibited',
            'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
