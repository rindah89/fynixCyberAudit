<?php

namespace App\Http\Requests;

use App\Enums\RiskDomain;
use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;

class ListOperationalLossEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $risk = $this->route('risk');
        $user = $this->user();

        return $risk instanceof Risk && $risk->domain === RiskDomain::Operational && $user !== null && ($user->can('Manage Risk Portfolio') || $user->can('Read Risks')
            || (int) $risk->governanceProfile?->owner_id === (int) $user->id);
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
