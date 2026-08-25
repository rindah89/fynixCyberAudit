<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyCollaborationClosureAcknowledgementDelivery;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeThirdPartyCollaborationClosureDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $delivery instanceof ThirdPartyCollaborationClosureAcknowledgementDelivery
            && $this->user()?->can('acknowledge', $delivery) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
