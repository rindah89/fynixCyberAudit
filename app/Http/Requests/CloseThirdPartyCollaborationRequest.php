<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyCollaborationRequestClosure;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationClosureManager;
use Illuminate\Foundation\Http\FormRequest;

class CloseThirdPartyCollaborationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('collaborationRequest') instanceof ThirdPartyEngagementCollaborationRequest
            && $this->user()?->can('create', ThirdPartyCollaborationRequestClosure::class) === true;
    }

    public function rules(): array
    {
        return ThirdPartyEngagementCollaborationClosureManager::rules();
    }
}
