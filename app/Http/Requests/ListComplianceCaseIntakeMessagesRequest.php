<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListComplianceCaseIntakeMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! Enterprise::enabled('compliance_cases')) {
            return false;
        }

        $intake = $this->route('intake');

        return $this->user()->can('Manage Compliance Cases') || ($intake && $intake->submitted_by === $this->user()->id && ! $this->user()->trashed());
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
