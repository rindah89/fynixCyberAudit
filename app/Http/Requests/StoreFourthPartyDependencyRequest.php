<?php

namespace App\Http\Requests;

use App\Services\FourthPartyDependencyManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreFourthPartyDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') ?? false;
    }

    public function rules(): array
    {
        return FourthPartyDependencyManager::rules() + [
            'vendor_id' => ['prohibited'], 'dependency_key' => ['prohibited'], 'version' => ['prohibited'],
            'recorded_by' => ['prohibited'], 'governance_snapshot' => ['prohibited'], 'recorded_at' => ['prohibited'],
        ];
    }
}
