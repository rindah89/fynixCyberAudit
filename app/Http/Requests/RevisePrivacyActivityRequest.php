<?php

namespace App\Http\Requests;

use App\Privacy\PrivacyManagementManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class RevisePrivacyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('privacy_management') && $this->user()?->can('update', $this->route('activity')) === true;
    }

    public function rules(): array
    {
        return PrivacyManagementManager::activityRules(false);
    }
}
