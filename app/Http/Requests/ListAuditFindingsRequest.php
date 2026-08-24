<?php

namespace App\Http\Requests;

use App\Models\Audit;
use Illuminate\Foundation\Http\FormRequest;

class ListAuditFindingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');

        return $audit instanceof Audit && $this->user()?->can('view', $audit);
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
