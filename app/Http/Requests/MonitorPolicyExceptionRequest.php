<?php

namespace App\Http\Requests;

use App\PolicyCompliance\PolicyExceptionMonitoringManager;
use Illuminate\Foundation\Http\FormRequest;

class MonitorPolicyExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('Update Policies');
    }

    public function rules(): array
    {
        return PolicyExceptionMonitoringManager::rules() + [
            'policy_exception_id' => ['prohibited'], 'version' => ['prohibited'],
            'exception_snapshot' => ['prohibited'], 'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'], 'next_review_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'evidence' => ['prohibited'], 'evidence_manifest' => ['prohibited'],
        ];
    }
}
