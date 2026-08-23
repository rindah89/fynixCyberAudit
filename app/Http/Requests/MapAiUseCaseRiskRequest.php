<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MapAiUseCaseRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return ['risk_id' => 'required|exists:risks,id'];
    }
}
