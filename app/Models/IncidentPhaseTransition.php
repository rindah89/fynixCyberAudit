<?php

namespace App\Models;

use App\Enums\IncidentPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class IncidentPhaseTransition extends Model
{
    protected $fillable = [
        'incident_id', 'from_phase', 'to_phase', 'summary', 'incident_snapshot',
        'transitioned_by', 'transitioned_at', 'fingerprint',
    ];

    protected $casts = [
        'from_phase' => IncidentPhase::class,
        'to_phase' => IncidentPhase::class,
        'incident_snapshot' => 'array',
        'transitioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Incident phase transitions are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Incident phase transitions are append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transitioned_by')->withTrashed();
    }
}
