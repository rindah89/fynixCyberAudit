<?php

namespace App\Http\Requests;

use App\Models\PrivacyRightsRequest;
use Illuminate\Foundation\Http\FormRequest;

class ShowPrivacyRightsRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('rightsRequest');

        return $record instanceof PrivacyRightsRequest && $this->user()?->can('view', $record) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
