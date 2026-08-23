<?php

namespace App\Http\Requests;

use App\Services\OperationalLossEventManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreOperationalLossEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Risk Portfolio') ?? false;
    }

    public function rules(): array
    {
        return OperationalLossEventManager::rules() + [
            'risk_id' => ['prohibited'],
            'business_service_id_snapshot' => ['prohibited'],
            'business_service_snapshot' => ['prohibited'],
            'reported_by' => ['prohibited'],
            'net_loss' => ['prohibited'],
            'recorded_at' => ['prohibited'],
        ];
    }
}
