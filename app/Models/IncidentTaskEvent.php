<?php

namespace App\Models;

use App\Enums\IncidentTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class IncidentTaskEvent extends Model
{
    protected $fillable = [
        'incident_id', 'incident_task_id', 'version', 'event_type', 'from_status', 'to_status',
        'before_snapshot', 'after_snapshot', 'summary', 'recorded_by', 'recorded_at', 'fingerprint',
    ];

    protected $casts = [
        'from_status' => IncidentTaskStatus::class, 'to_status' => IncidentTaskStatus::class,
        'before_snapshot' => 'array', 'after_snapshot' => 'array', 'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Incident task events are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Incident task events are append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(IncidentTask::class, 'incident_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
