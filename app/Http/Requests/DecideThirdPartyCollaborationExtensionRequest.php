<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyCollaborationExtension;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationExtensionManager;
use Illuminate\Foundation\Http\FormRequest;

class DecideThirdPartyCollaborationExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $extension = $this->route('extension');

        return $extension instanceof ThirdPartyCollaborationExtension
            && $this->user()?->can('Manage Third Party Risk') === true;
    }

    public function rules(): array
    {
        return ThirdPartyEngagementCollaborationExtensionManager::decisionRules();
    }
}
