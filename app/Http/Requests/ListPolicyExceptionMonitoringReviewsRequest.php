<?php

namespace App\Http\Requests;

use App\Models\PolicyException;
use Illuminate\Foundation\Http\FormRequest;

class ListPolicyExceptionMonitoringReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exception = $this->route('exception');

        return $exception instanceof PolicyException && ($exception->policy?->owner_id === $this->user()?->id
            || $this->user()?->can('Read Policies') || $this->user()?->can('Update Policies'));
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }
}
