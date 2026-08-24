<?php

namespace App\Http\Requests;

use App\ModelRisk\ModelRiskManager;
use App\Models\GovernedModel;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreGovernedModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('model_risk_management') && $this->user()?->can('create', GovernedModel::class) === true;
    }

    public function rules(): array
    {
        return ModelRiskManager::modelRules(true);
    }
}
