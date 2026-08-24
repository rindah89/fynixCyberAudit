<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListFourthPartyConcentrationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') || $this->user()?->can('Read Vendors');
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
