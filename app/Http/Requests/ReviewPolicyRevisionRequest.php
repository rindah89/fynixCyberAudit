<?php

namespace App\Http\Requests;

use App\Models\PolicyRevision;
use App\PolicyCompliance\PolicyRevisionManager;
use Illuminate\Foundation\Http\FormRequest;

class ReviewPolicyRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('revision') instanceof PolicyRevision && (bool) $this->user()?->can('Update Policies');
    }

    public function rules(): array
    {
        return PolicyRevisionManager::reviewRules() + [
            'policy_revision_id' => ['prohibited'], 'revision_snapshot' => ['prohibited'],
            'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }
}
