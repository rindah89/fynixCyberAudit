<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class GenerateIncidentFinalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('incident');

        return Enterprise::enabled('incidents') && $incident instanceof Incident
            && $this->user()?->can('update', $incident) === true && $this->user()?->can('Manage Incident Evidence') === true;
    }

    public function rules(): array
    {
        return [
            'executive_summary' => 'required|string|max:30000', 'conclusions' => 'required|string|max:30000',
            'version' => 'prohibited', 'report_snapshot' => 'prohibited', 'evidence_attachment_ids' => 'prohibited',
            'generated_by' => 'prohibited', 'generated_at' => 'prohibited', 'report_disk' => 'prohibited',
            'report_path' => 'prohibited', 'report_size' => 'prohibited', 'report_sha256' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
