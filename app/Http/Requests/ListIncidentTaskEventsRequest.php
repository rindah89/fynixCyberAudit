<?php

namespace App\Http\Requests;

use App\Models\IncidentTask;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListIncidentTaskEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        $actor = $this->user();

        return Enterprise::enabled('incidents') && $task instanceof IncidentTask && $actor !== null
            && ($actor->can('view', $task->incident) || $actor->can('Manage Incident Tasks') || $task->assignee_id === $actor->id);
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
