<?php

namespace App\Http\Requests;

use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use Illuminate\Foundation\Http\FormRequest;

class TransitionThirdPartyEngagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') === true;
    }

    public function rules(): array
    {
        return ThirdPartyEngagementManager::transitionRules();
    }
}
