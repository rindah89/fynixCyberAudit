<?php

namespace App\Http\Requests;

use App\Enums\ComplianceCaseIntakeAudience;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeComplianceCaseIntakeMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');

        return Enterprise::enabled('compliance_cases') && $message !== null
            && $message->audience === ComplianceCaseIntakeAudience::Reporter
            && $message->intake()->where('submitted_by', $this->user()->id)->exists()
            && ! $this->user()->trashed();
    }

    public function rules(): array
    {
        return ['id' => 'prohibited', 'recipient_id' => 'prohibited', 'recipient_snapshot' => 'prohibited',
            'message_snapshot' => 'prohibited', 'acknowledged_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }
}
