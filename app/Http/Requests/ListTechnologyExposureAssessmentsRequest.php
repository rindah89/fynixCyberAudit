<?php

namespace App\Http\Requests;

use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;

class ListTechnologyExposureAssessmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $risk = $this->route('risk');

        return $this->user() && $risk instanceof Risk && ($this->user()->can('Manage Risk Portfolio') || $this->user()->can('Read Risks') || $risk->governanceProfile?->owner_id === $this->user()->id);
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
