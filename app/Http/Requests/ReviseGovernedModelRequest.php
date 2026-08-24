<?php

namespace App\Http\Requests;

use App\ModelRisk\ModelRiskManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviseGovernedModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('model_risk_management') && $this->user()?->can('update', $this->route('governedModel')) === true;
    }

    public function rules(): array
    {
        return ModelRiskManager::modelRules(false);
    }
}
