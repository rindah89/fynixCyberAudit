<?php

namespace App\Http\Requests;

use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;

class ShowEnterpriseRiskRollupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $risk = $this->route('risk');

        return $user && $risk instanceof Risk && (
            $user->can('Manage Risk Portfolio')
            || $user->can('Read Risks')
            || $risk->governanceProfile()->where('owner_id', $user->id)->exists()
        );
    }

    public function rules(): array
    {
        return [];
    }
}
