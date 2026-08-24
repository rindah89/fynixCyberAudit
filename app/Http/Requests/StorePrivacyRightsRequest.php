<?php

namespace App\Http\Requests;

use App\Models\PrivacyRightsRequest;
use App\Privacy\PrivacyRightsRequestManager;
use Illuminate\Foundation\Http\FormRequest;

class StorePrivacyRightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PrivacyRightsRequest::class) === true;
    }

    public function rules(): array
    {
        return PrivacyRightsRequestManager::openRules();
    }
}
