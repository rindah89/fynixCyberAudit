<?php

namespace App\Http\Requests;

use App\Esg\EsgPerformanceManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('update', $this->route('goal')->topic) === true;
    }

    public function rules(): array
    {
        return EsgPerformanceManager::kpiRules();
    }
}
