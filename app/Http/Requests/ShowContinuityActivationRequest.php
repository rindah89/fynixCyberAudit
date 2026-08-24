<?php

namespace App\Http\Requests;

use App\Models\ContinuityActivation;
use Illuminate\Foundation\Http\FormRequest;

class ShowContinuityActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $activation = $this->route('activation');

        return $activation instanceof ContinuityActivation
            && $this->user()?->can('view', $activation->businessService) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
