<?php

namespace App\Http\Requests;

use App\ContinuousControlTesting\ControlTestRunner;
use App\Enums\ControlTestFrequency;
use App\Enums\ControlTestMetricType;
use App\Enums\ControlTestOperator;
use App\Models\Control;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreControlTestDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $control = $this->route('control');

        return $control instanceof Control && $this->user()?->can('update', $control) === true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:control_test_definitions,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'implementation_id' => 'nullable|exists:implementations,id',
            'owner_id' => 'required|exists:users,id',
            'metric_type' => ['required', Rule::enum(ControlTestMetricType::class)],
            'operator' => ['required', Rule::enum(ControlTestOperator::class)],
            'expected_value' => 'required|string|max:255',
            'frequency' => ['required', Rule::enum(ControlTestFrequency::class)],
            'next_run_at' => 'required|date',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                app(ControlTestRunner::class)->validateThreshold(
                    $this->string('metric_type')->toString(),
                    $this->string('operator')->toString(),
                    $this->string('expected_value')->toString(),
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }

            $control = $this->route('control');
            if ($this->filled('implementation_id') && $control instanceof Control
                && ! $control->implementations()->whereKey($this->integer('implementation_id'))->exists()) {
                $validator->errors()->add('implementation_id', 'The implementation must be mapped to this control.');
            }
        }];
    }
}
