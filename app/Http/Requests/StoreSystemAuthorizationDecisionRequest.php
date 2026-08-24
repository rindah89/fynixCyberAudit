<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use App\SystemAuthorization\SystemAuthorizationManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreSystemAuthorizationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('system_authorization') && $this->user()?->can('decide', $this->route('package')) === true;
    }

    public function rules(): array
    {
        return SystemAuthorizationManager::decisionRules();
    }
}
