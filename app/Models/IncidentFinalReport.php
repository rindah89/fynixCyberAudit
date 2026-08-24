<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class IncidentFinalReport extends Model
{
    use HasFactory;

    protected $hidden = ['report_snapshot', 'evidence_attachment_ids', 'report_disk', 'report_path'];

    protected $fillable = [
        'incident_id', 'version', 'report_snapshot', 'evidence_attachment_ids', 'generated_by', 'generated_at',
        'report_disk', 'report_path', 'report_size', 'report_sha256', 'fingerprint',
    ];

    protected $casts = [
        'report_snapshot' => 'array', 'evidence_attachment_ids' => 'array', 'generated_at' => 'datetime',
        'report_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident final reports are append-only.'));
        static::deleting(fn () => throw new LogicException('Incident final reports are append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }
}
