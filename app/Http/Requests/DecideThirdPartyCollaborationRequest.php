<?php

namespace App\Http\Requests;

use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use Illuminate\Foundation\Http\FormRequest;

class DecideThirdPartyCollaborationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk');
    }

    public function rules(): array
    {
        return ThirdPartyEngagementCollaborationManager::decisionRules();
    }
}
