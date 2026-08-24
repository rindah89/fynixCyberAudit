<?php

namespace App\Http\Requests;

use App\Models\AuditFinding;
use Illuminate\Foundation\Http\FormRequest;

class ShowAuditFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $finding = $this->route('finding');

        return $finding instanceof AuditFinding && ($finding->accountable_owner_id === $this->user()?->id || $this->user()?->can('view', $finding->audit));
    }

    public function rules(): array
    {
        return [];
    }
}
