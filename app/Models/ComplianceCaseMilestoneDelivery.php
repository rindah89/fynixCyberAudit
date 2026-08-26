<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseMilestoneDelivery extends Model
{
    protected $fillable = [
        'compliance_case_milestone_id', 'compliance_case_milestone_event_id', 'user_id', 'event_type',
        'channel', 'notification_id', 'recipient_snapshot', 'milestone_snapshot', 'attempted_at',
        'delivered_at', 'fingerprint',
    ];

    protected $casts = [
        'recipient_snapshot' => 'array', 'milestone_snapshot' => 'array',
        'attempted_at' => 'datetime', 'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Milestone delivery evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Milestone delivery evidence is retained.'));
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseMilestone::class, 'compliance_case_milestone_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseMilestoneEvent::class, 'compliance_case_milestone_event_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
