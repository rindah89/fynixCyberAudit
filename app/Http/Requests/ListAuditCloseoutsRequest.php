<?php

namespace App\Http\Requests;

use App\Models\Audit;
use Illuminate\Foundation\Http\FormRequest;

class ListAuditCloseoutsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');

        return $audit instanceof Audit && $this->user()?->can('view', $audit);
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
