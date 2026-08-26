<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCaseMilestone;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class CompleteComplianceCaseMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $milestone = $this->route('milestone');

        return Enterprise::enabled('compliance_cases') && $milestone instanceof ComplianceCaseMilestone
            && $this->user() !== null
            && ((int) $this->user()->id === (int) $milestone->owner_id || $this->user()->can('Manage Compliance Cases'))
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $milestone->compliance_case_id);
    }

    public function rules(): array
    {
        return [
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'fingerprint' => 'prohibited', 'event_type' => 'prohibited',
            'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited',
        ];
    }
}
