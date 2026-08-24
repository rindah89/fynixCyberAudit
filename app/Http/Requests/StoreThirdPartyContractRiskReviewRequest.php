<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagement;
use App\ThirdPartyRisk\ThirdPartyContractRiskManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreThirdPartyContractRiskReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('engagement') instanceof ThirdPartyEngagement
            && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk'));
    }

    public function rules(): array
    {
        return ThirdPartyContractRiskManager::rules();
    }
}
