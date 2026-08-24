<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RemediationTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'remediation_project_id',
        'number',
        'title',
        'status',
        'priority',
        'type',
        'owner_id',
        'assignee_id',
        'due_date',
        'weakness_description',
        'audit_item_id',
        'audit_finding_id',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (RemediationTask $task): void {
            if ($task->findingRemediation()->exists() && $task->isDirty(['remediation_project_id', 'number', 'audit_item_id', 'audit_finding_id'])) {
                throw new LogicException('A governed finding remediation task cannot change source identity.');
            }
        });
        static::deleting(function (RemediationTask $task): void {
            if ($task->findingRemediation()->exists()) {
                throw new LogicException('A governed finding remediation task cannot be deleted.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(RemediationProject::class, 'remediation_project_id');
    }

    public function auditItem(): BelongsTo
    {
        return $this->belongsTo(AuditItem::class);
    }

    public function auditFinding(): BelongsTo
    {
        return $this->belongsTo(AuditFinding::class);
    }

    public function findingRemediation(): HasOne
    {
        return $this->hasOne(AuditFindingRemediation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
