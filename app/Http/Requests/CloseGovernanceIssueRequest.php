<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseGovernanceIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Verify Issue Closure') ?? false;
    }

    public function rules(): array
    {
        return [
            'verification_summary' => ['required', 'string', 'max:30000'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'evidence_attachment_ids' => ['required', 'array', 'min:1', 'max:20'],
            'evidence_attachment_ids.*' => ['required', 'integer', 'distinct', 'exists:file_attachments,id'],
        ];
    }
}
