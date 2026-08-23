<?php

namespace App\Http\Requests;

use App\Enums\PolicyAttestationOutcome;
use App\Models\PolicyObligation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePolicyAttestationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $obligation = $this->route('obligation');

        return $obligation instanceof PolicyObligation && ($this->user()?->can('attest', $obligation) ?? false);
    }

    public function rules(): array
    {
        /** @var PolicyObligation $obligation */
        $obligation = $this->route('obligation');

        return [
            'outcome' => ['required', Rule::enum(PolicyAttestationOutcome::class)],
            'statement' => 'required|string|max:10000',
            'evidence_reference' => 'nullable|string|max:255',
            'policy_exception_id' => ['nullable', Rule::exists('policy_exceptions', 'id')->where('policy_id', $obligation->policy_id)],
            'evidence_attachment_ids' => 'sometimes|array|max:20',
            'evidence_attachment_ids.*' => 'required|integer|distinct|exists:file_attachments,id',
        ];
    }
}
