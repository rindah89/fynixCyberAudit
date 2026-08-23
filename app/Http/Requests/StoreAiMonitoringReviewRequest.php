<?php

namespace App\Http\Requests;

use App\Enums\AiMonitoringOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiMonitoringReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(AiMonitoringOutcome::class)], 'performance_summary' => 'required|string|max:30000',
            'incidents_count' => 'sometimes|integer|min:0|max:1000000', 'complaints_count' => 'sometimes|integer|min:0|max:1000000',
            'evidence_reference' => 'nullable|string|max:255', 'next_review_at' => 'required|date|after:today',
            'evidence_attachment_ids' => 'sometimes|array|max:20',
            'evidence_attachment_ids.*' => 'integer|distinct|exists:file_attachments,id',
        ];
    }
}
