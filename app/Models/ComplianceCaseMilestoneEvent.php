<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseMilestoneEvent extends Model
{
    protected $fillable = [
        'compliance_case_milestone_id', 'event_type', 'summary', 'recorded_by', 'actor_snapshot',
        'milestone_snapshot', 'recorded_at', 'fingerprint',
    ];

    protected $hidden = ['milestone_snapshot'];

    protected $casts = ['actor_snapshot' => 'array', 'milestone_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed milestone events are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed milestone events are retained.'));
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseMilestone::class, 'compliance_case_milestone_id');
    }
}
