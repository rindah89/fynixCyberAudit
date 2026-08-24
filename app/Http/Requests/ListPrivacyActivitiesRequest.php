<?php

namespace App\Http\Requests;

use App\Models\PrivacyProcessingActivity;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListPrivacyActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('privacy_management') && $this->user()?->can('viewAny', PrivacyProcessingActivity::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
