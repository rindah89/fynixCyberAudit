<?php

namespace App\Http\Requests;

use App\Enums\ResilienceCriticality;
use App\Models\BusinessService;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBusinessServiceDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'dependent_service_id' => 'nullable|exists:business_services,id', 'application_id' => 'nullable|exists:applications,id',
            'asset_id' => 'nullable|exists:assets,id', 'vendor_id' => 'nullable|exists:vendors,id', 'control_id' => 'nullable|exists:controls,id',
            'dependency_type' => 'required|string|max:255', 'criticality' => ['required', Rule::enum(ResilienceCriticality::class)], 'notes' => 'nullable|string|max:10000',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $targets = collect(['dependent_service_id', 'application_id', 'asset_id', 'vendor_id', 'control_id'])->filter(fn ($field) => $this->filled($field));
            if ($targets->count() !== 1) {
                $validator->errors()->add('dependency', 'Select exactly one dependency target.');
            }
            $service = $this->route('service');
            if ($service instanceof BusinessService && $this->integer('dependent_service_id') === $service->id) {
                $validator->errors()->add('dependent_service_id', 'A service cannot depend on itself.');
            }
        }];
    }
}
