<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ClaimEvidenceAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', 'in:deploy'],
            'nonce' => ['required', 'uuid'],
            'ttl_seconds' => ['required', 'integer', 'between:60,600'],
            'request_digest' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'claim_token' => ['sometimes', 'required', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! in_array(count($this->all()), [4, 5], true) || array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('request', 'Closed claim schema required.');
            }
        }];
    }
}
