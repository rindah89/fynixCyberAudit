<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MapAiUseCaseControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return ['control_id' => 'required|exists:controls,id'];
    }
}
