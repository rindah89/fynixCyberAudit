<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Enums\GovernanceIssueType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GovernanceIssueLifecycle extends Model
{
    protected $fillable = ['issue_type', 'issue_id', 'status', 'remediation_task_id', 'due_at', 'verification_summary', 'evidence_reference', 'verified_by', 'verified_at', 'closed_by', 'closed_at'];

    protected $casts = ['status' => GovernanceIssueStatus::class, 'due_at' => 'date', 'verified_at' => 'datetime', 'closed_at' => 'datetime'];

    public function issue(): MorphTo
    {
        return $this->morphTo();
    }

    public function remediationTask(): BelongsTo
    {
        return $this->belongsTo(RemediationTask::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(GovernanceIssueTransition::class);
    }

    public function closureEvidence(): HasMany
    {
        return $this->hasMany(GovernanceIssueClosureEvidence::class);
    }

    public function scopeWithIssueGraph(Builder $query): Builder
    {
        return $query->with([
            'issue' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                RiskGovernanceIssue::class => ['owner'], VendorRiskIssue::class => ['owner'],
                AiGovernanceIssue::class => ['owner'], ResilienceIssue::class => ['owner'],
                ControlTestFinding::class => ['owner'],
            ]),
            'remediationTask.project', 'verifier:id,name', 'closer:id,name',
            'closureEvidence.linkedBy:id,name',
        ]);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('Manage Issue Lifecycle') || $user->can('Verify Issue Closure')) {
            return $query;
        }

        return $query->whereHasMorph('issue', collect(GovernanceIssueType::cases())->map->modelClass()->all(), fn (Builder $issue) => $issue->where('owner_id', $user->id));
    }

    public function getSourceTypeAttribute(): GovernanceIssueType
    {
        return GovernanceIssueType::fromModelClass($this->issue_type);
    }
}
