<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGovernanceIssueRemediationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Issue Lifecycle') ?? false;
    }

    public function rules(): array
    {
        return [
            'remediation_project_id' => ['required', 'integer', 'exists:remediation_projects,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', 'string', 'in:Low,Medium,High,Critical'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'rationale' => ['required', 'string', 'max:30000'],
        ];
    }
}
