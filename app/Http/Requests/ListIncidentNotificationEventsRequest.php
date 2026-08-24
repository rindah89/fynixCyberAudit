<?php

namespace App\Http\Requests;

use App\Models\IncidentNotification;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListIncidentNotificationEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $notification = $this->route('notification');

        return Enterprise::enabled('incidents') && $notification instanceof IncidentNotification
            && $this->user()?->can('view', $notification->incident) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
