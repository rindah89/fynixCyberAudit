<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListIncidentFinalReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents') && $incident instanceof Incident
            && $this->user()?->can('view', $incident) === true && $this->user()?->can('Manage Incident Evidence') === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
