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
        'investigation_planning_governed_at', 'investigation_reporting_governed_at',
    ];

    protected $casts = [
        'category' => ComplianceCaseCategory::class, 'priority' => ComplianceCasePriority::class,
        'status' => ComplianceCaseStatus::class, 'confidential' => 'boolean', 'due_at' => 'datetime',
        'opened_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'governed_at' => 'datetime',
        'investigation_planning_governed_at' => 'datetime', 'investigation_reporting_governed_at' => 'datetime',
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

    public function interviews(): HasMany
    {
        return $this->hasMany(ComplianceCaseInterview::class)->orderBy('id');
    }

    public function legalHolds(): HasMany
    {
        return $this->hasMany(ComplianceCaseLegalHold::class)->orderBy('version');
    }

    public function investigationPlans(): HasMany
    {
        return $this->hasMany(ComplianceCaseInvestigationPlan::class)->orderBy('version');
    }

    public function investigationProcedureExecutions(): HasMany
    {
        return $this->hasMany(ComplianceCaseInvestigationProcedureExecution::class)->orderBy('procedure_index')->orderBy('version');
    }

    public function investigationReports(): HasMany
    {
        return $this->hasMany(ComplianceCaseInvestigationReport::class)->orderBy('version');
    }

    public function conflictDeclarations(): HasMany
    {
        return $this->hasMany(ComplianceCaseConflictDeclaration::class)->orderBy('version');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ComplianceCaseMilestone::class)->orderBy('version');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(ComplianceCaseAccessGrant::class)->orderBy('version');
    }

    public function communicationDecisions(): HasMany
    {
        return $this->hasMany(ComplianceCaseCommunicationDecision::class)->orderBy('version');
    }

    public function reopenProposals(): HasMany
    {
        return $this->hasMany(ComplianceCaseReopenProposal::class)->orderBy('version');
    }

    public function retentionClassifications(): HasMany
    {
        return $this->hasMany(ComplianceCaseRetentionClassification::class)->orderBy('version');
    }

    public function archiveManifests(): HasMany
    {
        return $this->hasMany(ComplianceCaseArchiveManifest::class)->orderBy('version');
    }

    public function closureReports(): HasMany
    {
        return $this->hasMany(ComplianceCaseClosureReport::class)->orderBy('version');
    }

    public function getInvestigationPlanningGovernanceStatusAttribute(): string
    {
        return $this->investigation_planning_governed_at === null ? 'legacy' : 'governed';
    }

    public function getInvestigationReportingGovernanceStatusAttribute(): string
    {
        return $this->investigation_reporting_governed_at === null ? 'legacy' : 'governed';
    }
}
