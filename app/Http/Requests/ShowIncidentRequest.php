<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ShowIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents')
            && $incident instanceof Incident
            && $this->user()?->can('view', $incident) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
