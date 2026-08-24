<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IncidentNotificationEvent extends Model
{
    protected $fillable = [
        'incident_id', 'incident_notification_id', 'version', 'event_type', 'before_snapshot',
        'after_snapshot', 'rationale', 'recorded_by', 'recorded_at', 'fingerprint',
    ];

    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident notification events are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Incident notification events are append-only through product interfaces.'));
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(IncidentNotification::class, 'incident_notification_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
