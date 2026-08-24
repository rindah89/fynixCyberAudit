<?php

namespace App\Http\Requests;

use App\Models\GovernedModel;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListGovernedModelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('model_risk_management') && $this->user()?->can('viewAny', GovernedModel::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
