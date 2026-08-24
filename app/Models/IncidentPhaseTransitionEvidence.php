<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IncidentPhaseTransitionEvidence extends Model
{
    protected $table = 'incident_phase_transition_evidence';

    protected $fillable = [
        'incident_phase_transition_id', 'file_attachment_id', 'data_request_response_id_snapshot',
        'response_status_snapshot', 'data_request_id_snapshot', 'audit_id_snapshot', 'linked_by',
        'disk_snapshot', 'file_name_snapshot', 'file_path_snapshot', 'file_size_snapshot', 'sha256', 'linked_at',
    ];

    protected $casts = ['linked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident phase-transition evidence is append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Incident phase-transition evidence is append-only through product interfaces.'));
    }

    public function transition(): BelongsTo
    {
        return $this->belongsTo(IncidentPhaseTransition::class, 'incident_phase_transition_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(FileAttachment::class, 'file_attachment_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by')->withTrashed();
    }
}
