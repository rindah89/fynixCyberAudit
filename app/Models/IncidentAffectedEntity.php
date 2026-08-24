<?php

namespace App\Models;

use App\Enums\IncidentAffectedEntityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IncidentAffectedEntity extends Model
{
    protected $fillable = [
        'incident_id', 'entity_type', 'entity_id_snapshot', 'entity_snapshot', 'impact_summary',
        'control_failure_note', 'linked_by', 'linked_at', 'fingerprint',
    ];

    protected $casts = ['entity_type' => IncidentAffectedEntityType::class, 'entity_snapshot' => 'array', 'linked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident affected-entity evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Incident affected-entity evidence is append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by')->withTrashed();
    }
}
