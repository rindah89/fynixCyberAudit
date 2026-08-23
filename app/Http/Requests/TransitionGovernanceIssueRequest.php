<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransitionGovernanceIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Issue Lifecycle') ?? false;
    }

    public function rules(): array
    {
        return ['rationale' => ['required', 'string', 'max:30000']];
    }
}
