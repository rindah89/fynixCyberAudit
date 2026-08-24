<?php

namespace App\Http\Requests;

use App\Models\RegulatorySource;
use App\PolicyCompliance\RegulatoryChangeManager;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRegulatorySourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $source = $this->route('source');

        return $source instanceof RegulatorySource && ($this->user()?->can('Update Policies') || $source->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return RegulatoryChangeManager::sourceRules($this->route('source')) + ['created_by' => ['prohibited'], 'updated_by' => ['prohibited']];
    }
}
