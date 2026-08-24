<?php

namespace App\Http\Requests;

use App\Models\PrivacyProcessingActivity;
use App\Privacy\PrivacyManagementManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StorePrivacyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('privacy_management') && $this->user()?->can('create', PrivacyProcessingActivity::class) === true;
    }

    public function rules(): array
    {
        return PrivacyManagementManager::activityRules(true);
    }
}
