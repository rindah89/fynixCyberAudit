<?php

namespace App\Http\Requests;

use App\Models\PolicyException;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use Illuminate\Foundation\Http\FormRequest;

class DecidePolicyExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('exception') instanceof PolicyException && (bool) $this->user()?->can('Update Policies');
    }

    public function rules(): array
    {
        return PolicyExceptionGovernanceManager::decisionRules() + [
            'policy_exception_id' => ['prohibited'], 'version' => ['prohibited'],
            'exception_snapshot' => ['prohibited'], 'decided_by' => ['prohibited'],
            'decided_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }
}
