<?php

namespace App\Models;

use App\Enums\ThirdPartyEngagementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ThirdPartyEngagement extends Model
{
    use HasFactory;

    protected $fillable = ['vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'proposed_by', 'term_start_at', 'term_end_at', 'next_review_at', 'approved_by', 'approved_at', 'activated_at', 'exited_at', 'exit_summary', 'data_disposition_statement', 'vendor_snapshot', 'approval_snapshot', 'governed_at'];

    protected $casts = ['status' => ThirdPartyEngagementStatus::class, 'data_access' => 'boolean', 'term_start_at' => 'date', 'term_end_at' => 'date', 'next_review_at' => 'date', 'approved_at' => 'datetime', 'activated_at' => 'datetime', 'exited_at' => 'datetime', 'vendor_snapshot' => 'array', 'approval_snapshot' => 'array', 'governed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Third-party engagements are retained governance history.'));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function businessOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_owner_id')->withTrashed();
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThirdPartyEngagementEvent::class)->orderBy('version');
    }

    public function contractRiskReviews(): HasMany
    {
        return $this->hasMany(ThirdPartyContractRiskReview::class);
    }
}
