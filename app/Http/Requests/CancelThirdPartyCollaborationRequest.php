<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationCancellationManager;
use Illuminate\Foundation\Http\FormRequest;

class CancelThirdPartyCollaborationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('collaborationRequest') instanceof ThirdPartyEngagementCollaborationRequest
            && $this->user()?->can('Manage Third Party Risk') === true;
    }

    public function rules(): array
    {
        return ThirdPartyEngagementCollaborationCancellationManager::rules();
    }
}
