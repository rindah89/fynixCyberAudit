<?php

namespace App\Http\Requests;

use App\PolicyCompliance\RegulatoryChangeManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreRegulatorySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Update Policies') ?? false;
    }

    public function rules(): array
    {
        return RegulatoryChangeManager::sourceRules() + ['created_by' => ['prohibited'], 'updated_by' => ['prohibited']];
    }
}
