<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataProcessor extends Model
{
    protected $guarded = [];

    protected $casts = ['data_categories' => 'array', 'processing_countries' => 'array', 'active' => 'boolean', 'review_due_at' => 'immutable_date', 'reviewed_at' => 'immutable_datetime'];

    /** @return array<string, mixed> */
    public function materialEvidence(): array
    {
        $categories = $this->data_categories ?? [];
        $countries = $this->processing_countries ?? [];
        sort($categories, SORT_STRING);
        sort($countries, SORT_STRING);

        return [
            'id' => $this->getKey(), 'name' => $this->name, 'purpose' => $this->purpose,
            'data_categories' => $categories, 'processing_countries' => $countries,
            'transfer_mechanism' => $this->transfer_mechanism,
            'agreement_owner' => $this->agreement_owner,
            'agreement_evidence_ref' => $this->agreement_evidence_ref,
            'agreement_evidence_sha256' => $this->agreement_evidence_sha256,
            'review_due_at' => $this->review_due_at?->toDateString(), 'active' => $this->active,
        ];
    }
}
