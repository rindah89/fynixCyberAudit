<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCaseReopenProposal;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseReopenProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('proposal');

        return Enterprise::enabled('compliance_cases') && $proposal instanceof ComplianceCaseReopenProposal
            && $this->user()?->can('Manage Compliance Cases') === true
            && $this->user()?->can('view', $proposal->complianceCase) === true
            && (int) $this->user()->id !== (int) $proposal->proposed_by
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $proposal->compliance_case_id);
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|in:approved,rejected', 'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'fingerprint' => 'prohibited', 'reviewed_by' => 'prohibited',
            'reviewed_at' => 'prohibited', 'reviewer_snapshot' => 'prohibited', 'proposal_snapshot' => 'prohibited',
        ];
    }
}
