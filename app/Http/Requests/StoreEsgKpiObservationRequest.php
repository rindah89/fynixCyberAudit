<?php

namespace App\Http\Requests;

use App\Esg\EsgPerformanceManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgKpiObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $kpi = $this->route('kpi');
        $topic = $kpi->goal->topic;
        $actor = $this->user();

        return Enterprise::enabled('esg_management') && ($actor?->can('update', $topic) === true || ($actor?->can('Own ESG Topics') === true && in_array($actor->id, [$topic->owner_id, $kpi->goal->owner_id, $kpi->owner_id], true)));
    }

    public function rules(): array
    {
        return EsgPerformanceManager::observationRules();
    }
}
