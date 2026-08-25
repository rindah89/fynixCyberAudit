<?php

namespace App\Models;

use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
        'reporter_reference', 'confidential', 'opened_by', 'assigned_to', 'due_at', 'triage_summary',
        'investigation_summary', 'resolution_summary', 'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
    ];

    protected $casts = [
        'category' => ComplianceCaseCategory::class, 'priority' => ComplianceCasePriority::class,
        'status' => ComplianceCaseStatus::class, 'confidential' => 'boolean', 'due_at' => 'datetime',
        'opened_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'governed_at' => 'datetime',
    ];

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ComplianceCaseEvent::class)->orderBy('version');
    }

    public function evidenceSubmissions(): HasMany
    {
        return $this->hasMany(ComplianceCaseEvidenceSubmission::class)->orderBy('version');
    }

    public function actionIssues(): HasMany
    {
        return $this->hasMany(ComplianceCaseActionIssue::class)->orderBy('id');
    }
}
