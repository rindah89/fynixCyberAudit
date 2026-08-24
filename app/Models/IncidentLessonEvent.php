<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IncidentLessonEvent extends Model
{
    protected $fillable = [
        'incident_id', 'incident_lesson_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot',
        'rationale', 'recorded_by', 'recorded_at', 'fingerprint',
    ];

    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident lesson events are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Incident lesson events are append-only through product interfaces.'));
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(IncidentLesson::class, 'incident_lesson_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
