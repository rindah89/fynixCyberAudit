<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AuditFindingRemediation extends Model
{
    use HasFactory;

    protected $fillable = ['audit_finding_id', 'audit_management_response_id', 'remediation_task_id', 'finding_snapshot', 'response_snapshot', 'task_snapshot', 'handed_off_by', 'handed_off_at', 'fingerprint'];

    protected $casts = ['finding_snapshot' => 'array', 'response_snapshot' => 'array', 'task_snapshot' => 'array', 'handed_off_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed finding remediation handoffs are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed finding remediation handoffs cannot be deleted.'));
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(AuditFinding::class, 'audit_finding_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(AuditManagementResponse::class, 'audit_management_response_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(RemediationTask::class, 'remediation_task_id');
    }

    public function handoffActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_off_by')->withTrashed();
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(AuditFindingFollowUp::class);
    }

    public function fingerprintPayload(): array
    {
        return $this->only(['audit_finding_id', 'audit_management_response_id', 'remediation_task_id', 'finding_snapshot', 'response_snapshot', 'task_snapshot', 'handed_off_by']) + [
            'handed_off_at' => $this->handed_off_at->toIso8601String(),
        ];
    }
}
