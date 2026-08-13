<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemediationTask extends Model
{
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
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(RemediationProject::class, 'remediation_project_id');
    }

    public function auditItem(): BelongsTo
    {
        return $this->belongsTo(AuditItem::class);
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
