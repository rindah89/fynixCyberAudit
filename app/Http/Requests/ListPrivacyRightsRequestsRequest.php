<?php

namespace App\Http\Requests;

use App\Models\PrivacyRightsRequest;
use Illuminate\Foundation\Http\FormRequest;

class ListPrivacyRightsRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PrivacyRightsRequest::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
