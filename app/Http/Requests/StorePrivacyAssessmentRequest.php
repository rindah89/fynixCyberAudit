<?php

namespace App\Http\Requests;

use App\Privacy\PrivacyManagementManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StorePrivacyAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('privacy_management') && $this->user()?->can('assess', $this->route('activity')) === true;
    }

    public function rules(): array
    {
        return PrivacyManagementManager::assessmentRules();
    }
}
