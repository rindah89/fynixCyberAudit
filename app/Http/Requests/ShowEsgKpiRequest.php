<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ShowEsgKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('view', $this->route('kpi')->goal->topic) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
