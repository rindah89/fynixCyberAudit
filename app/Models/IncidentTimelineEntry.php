<?php

namespace App\Models;

use App\Enums\IncidentTimelineEntryType;
use App\Enums\IncidentTimelineVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IncidentTimelineEntry extends Model
{
    protected $fillable = [
        'incident_id', 'version', 'entry_type', 'visibility', 'occurred_at', 'summary', 'details',
        'pinned', 'incident_snapshot', 'recorded_by', 'recorded_at', 'fingerprint',
    ];

    protected $casts = [
        'entry_type' => IncidentTimelineEntryType::class, 'visibility' => IncidentTimelineVisibility::class,
        'occurred_at' => 'datetime', 'recorded_at' => 'datetime', 'pinned' => 'boolean', 'incident_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident timeline evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Incident timeline evidence is append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
