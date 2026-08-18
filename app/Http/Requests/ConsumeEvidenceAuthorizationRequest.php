<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConsumeEvidenceAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', 'in:deploy'],
            'operation_id' => ['required', 'uuid'],
            'request_digest' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'claim_token' => ['required', 'string', 'size:64'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (count($this->all()) !== 4 || array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('request', 'Closed consume schema required.');
            }
        }];
    }
}
