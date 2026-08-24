<?php

namespace App\Http\Requests;

use App\Models\IncidentLesson;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListIncidentLessonEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');
        $actor = $this->user();

        return Enterprise::enabled('incidents') && $lesson instanceof IncidentLesson && $actor !== null
            && ($actor->can('view', $lesson->incident) || $lesson->owner_id === $actor->id);
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
