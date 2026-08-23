<?php

namespace App\Http\Requests;

use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;

class ListEnterpriseRiskScenariosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $risk = $this->route('risk');

        return $risk instanceof Risk && ($this->user()?->can('Manage Risk Portfolio') || $this->user()?->can('Read Risks') || $risk->governanceProfile?->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
