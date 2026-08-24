<?php

namespace App\Http\Requests;

use App\Models\AuditCloseoutSubmission;
use App\Services\AuditCloseoutManager;
use Illuminate\Foundation\Http\FormRequest;

class ReviewAuditCloseoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $submission = $this->route('submission');

        return $submission instanceof AuditCloseoutSubmission
            && $this->user()?->can('Update Audits')
            && $submission->submitted_by !== $this->user()?->id
            && $submission->audit()->where('manager_id', '!=', $this->user()?->id)->exists();
    }

    public function rules(): array
    {
        return AuditCloseoutManager::reviewRules() + [
            'report_snapshot' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'],
            'report_disk' => ['prohibited'], 'report_path' => ['prohibited'], 'report_size' => ['prohibited'],
            'report_sha256' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }
}
