<?php

namespace App\Http\Requests;

use App\Models\Audit;
use App\Services\AuditCloseoutManager;
use Illuminate\Foundation\Http\FormRequest;

class SubmitAuditCloseoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');

        return $audit instanceof Audit && ($audit->manager_id === $this->user()?->id || $this->user()?->can('Update Audits'));
    }

    public function rules(): array
    {
        return AuditCloseoutManager::submissionRules() + [
            'version' => ['prohibited'], 'audit_snapshot' => ['prohibited'], 'engagement_baseline_snapshot' => ['prohibited'],
            'audit_item_snapshots' => ['prohibited'], 'data_request_snapshots' => ['prohibited'],
            'submitted_by' => ['prohibited'], 'submitted_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }
}
