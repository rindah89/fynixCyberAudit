<?php

namespace App\Http\Requests;

use App\Models\AuditPlanItem;
use Illuminate\Foundation\Http\FormRequest;

class LaunchAuditEngagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof AuditPlanItem
            && $this->user()?->can('Create Audits')
            && ($this->user()?->can('Update Programs') || $item->plan()->where('manager_id', $this->user()?->id)->exists());
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:30000'],
            'audit_type' => ['required', 'string', 'in:controls,implementations'],
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'objective' => ['required', 'string', 'max:30000'],
            'scope' => ['required', 'string', 'max:30000'],
            'exclusions' => ['nullable', 'string', 'max:30000'],
            'team_user_ids' => ['sometimes', 'array', 'max:100'],
            'team_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'status' => ['prohibited'],
            'start_date' => ['prohibited'],
            'end_date' => ['prohibited'],
            'fingerprint' => ['prohibited'],
            'launched_by' => ['prohibited'],
            'launched_at' => ['prohibited'],
            'plan_snapshot' => ['prohibited'],
            'entity_assessment_snapshot' => ['prohibited'],
        ];
    }
}
