<?php

namespace App\Http\Requests;

use App\Models\ControlTestDefinition;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteControlTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $definition = $this->route('definition');

        return $definition instanceof ControlTestDefinition && $this->user()?->can('execute', $definition) === true;
    }

    public function rules(): array
    {
        return [
            'observed_value' => 'required|string|max:255',
            'notes' => 'nullable|string|max:10000',
            'evidence_reference' => 'nullable|string|max:255',
            'outcome' => 'prohibited',
        ];
    }
}
