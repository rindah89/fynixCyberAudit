<?php

namespace App\Http\Requests;

use App\Models\SystemAuthorizationPackage;
use App\Support\Enterprise;
use App\SystemAuthorization\SystemAuthorizationManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreSystemAuthorizationPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('system_authorization') && $this->user()?->can('create', SystemAuthorizationPackage::class) === true && $this->user()?->can('view', $this->route('application')) === true;
    }

    public function rules(): array
    {
        return SystemAuthorizationManager::packageRules();
    }
}
