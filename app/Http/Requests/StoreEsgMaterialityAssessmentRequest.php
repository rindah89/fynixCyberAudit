<?php

namespace App\Http\Requests;

use App\Esg\EsgMaterialityManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgMaterialityAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('assess', $this->route('topic')) === true;
    }

    public function rules(): array
    {
        return EsgMaterialityManager::assessmentRules();
    }
}
