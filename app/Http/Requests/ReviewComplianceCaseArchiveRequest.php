<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCaseArchiveManifest;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $archive = $this->route('archive');

        return Enterprise::enabled('compliance_cases') && $archive instanceof ComplianceCaseArchiveManifest
            && $this->user()?->can('Manage Compliance Cases') === true
            && $this->user()?->can('view', $archive->complianceCase) === true
            && (int) $this->user()->id !== (int) $archive->generated_by
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $archive->compliance_case_id);
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|in:approved,rejected', 'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'fingerprint' => 'prohibited', 'reviewed_by' => 'prohibited',
            'reviewed_at' => 'prohibited', 'reviewer_snapshot' => 'prohibited', 'manifest_snapshot' => 'prohibited',
        ];
    }
}
