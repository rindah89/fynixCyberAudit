<?php

namespace App\Models;

use App\Enums\RiskReviewFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskGovernanceProfile extends Model
{
    protected $fillable = ['risk_id', 'owner_id', 'appetite_threshold', 'review_frequency', 'strategic_objective', 'business_service_id', 'context_notes', 'next_review_at'];

    protected $casts = ['review_frequency' => RiskReviewFrequency::class, 'next_review_at' => 'date'];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }
}
