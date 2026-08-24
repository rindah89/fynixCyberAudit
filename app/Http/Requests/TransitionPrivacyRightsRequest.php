<?php

namespace App\Http\Requests;

use App\Models\PrivacyRightsRequest;
use App\Privacy\PrivacyRightsRequestManager;
use Illuminate\Foundation\Http\FormRequest;

class TransitionPrivacyRightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('rightsRequest');

        return $record instanceof PrivacyRightsRequest && $this->user()?->can('update', $record) === true;
    }

    public function rules(): array
    {
        $rules = PrivacyRightsRequestManager::transitionRules();
        if (! $this->user()?->can('Manage Privacy Rights')) {
            $rules['assigned_to'] = 'prohibited';
        }

        return $rules;
    }
}
