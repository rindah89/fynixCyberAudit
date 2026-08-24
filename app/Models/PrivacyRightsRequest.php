<?php

namespace App\Models;

use App\Enums\PrivacyRightsRequestStatus;
use App\Enums\PrivacyRightsRequestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PrivacyRightsRequest extends Model
{
    use HasFactory;

    protected $appends = ['due_state'];

    protected $fillable = ['number', 'request_type', 'status', 'data_subject_name', 'data_subject_email', 'subject_reference', 'request_details', 'intake_channel', 'jurisdiction_reference', 'received_at', 'due_at', 'assigned_to', 'opened_by', 'identity_verification_summary', 'response_summary', 'decision_basis', 'delivery_reference', 'completed_at', 'governed_at'];

    protected $casts = ['request_type' => PrivacyRightsRequestType::class, 'status' => PrivacyRightsRequestStatus::class, 'received_at' => 'datetime', 'due_at' => 'datetime', 'completed_at' => 'datetime', 'governed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Privacy rights requests are retained sensitive governance history.'));
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrivacyRightsRequestEvent::class)->orderBy('version');
    }

    public function getDueStateAttribute(): string
    {
        if (in_array($this->status, [PrivacyRightsRequestStatus::Fulfilled, PrivacyRightsRequestStatus::Denied, PrivacyRightsRequestStatus::Withdrawn], true)) {
            return 'complete';
        }

        return $this->due_at->isPast() ? 'overdue' : 'current';
    }
}
