<?php

namespace App\Http\Requests;

use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('complianceCase') ?? $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase
            && $this->user()?->can('Manage Compliance Cases') === true && $this->user()?->can('view', $case) === true;
    }

    public function rules(): array
    {
        return [
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
            'archive_disk' => 'prohibited', 'archive_path' => 'prohibited', 'archive_size' => 'prohibited',
            'archive_sha256' => 'prohibited', 'source_fingerprints' => 'prohibited', 'generated_by' => 'prohibited',
            'generated_at' => 'prohibited', 'generator_snapshot' => 'prohibited',
        ];
    }
}
