<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Enums\PolicyExceptionMonitoringOutcome;
use App\Enums\PolicyExceptionMonitoringState;
use App\Enums\PolicyExceptionStatus;
use Database\Factories\PolicyExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PolicyException
 *
 * Represents an exception to a policy in the GRC system.
 * Policy exceptions allow temporary or permanent deviations from policy requirements.
 */
class PolicyException extends Model
{
    /** @use HasFactory<PolicyExceptionFactory> */
    use HasFactory, SoftDeletes;

    protected $appends = ['monitoring_status'];

    protected $fillable = [
        'policy_id',
        'name',
        'description',
        'justification',
        'risk_assessment',
        'compensating_controls',
        'governance_snapshot',
        'governance_fingerprint',
        'status',
        'requested_date',
        'submitted_at',
        'effective_date',
        'expiration_date',
        'review_frequency_days',
        'next_review_at',
        'latest_monitoring_outcome',
        'requested_by',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => PolicyExceptionStatus::class,
        'requested_date' => 'date',
        'effective_date' => 'date',
        'expiration_date' => 'date',
        'review_frequency_days' => 'integer',
        'next_review_at' => 'datetime',
        'latest_monitoring_outcome' => PolicyExceptionMonitoringOutcome::class,
        'governance_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if ($model->getRawOriginal('governance_fingerprint')) {
                $allowed = ['status', 'approved_by', 'next_review_at', 'latest_monitoring_outcome', 'updated_by', 'updated_at'];
                if (array_diff(array_keys($model->getDirty()), $allowed) !== []) {
                    throw new \LogicException('Governed policy exception requests are immutable.');
                }
                if ($model->isDirty('status')) {
                    $from = PolicyExceptionStatus::from($model->getRawOriginal('status'));
                    $valid = ($from === PolicyExceptionStatus::Pending && in_array($model->status, [PolicyExceptionStatus::Approved, PolicyExceptionStatus::Denied], true))
                        || ($from === PolicyExceptionStatus::Approved && $model->status === PolicyExceptionStatus::Revoked);
                    if (! $valid) {
                        throw new \LogicException('The governed policy exception transition is invalid.');
                    }
                }
            }
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
        static::deleting(function (self $model): void {
            if ($model->governance_fingerprint) {
                throw new \LogicException('Governed policy exception requests cannot be deleted.');
            }
        });
    }

    /**
     * Get the policy that this exception belongs to.
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class)->withTrashed();
    }

    /**
     * Get the user who requested this exception.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /**
     * Get the user who approved this exception.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    /**
     * Get the user who created this exception.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this exception.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(PolicyExceptionDecision::class);
    }

    public function monitoringReviews(): HasMany
    {
        return $this->hasMany(PolicyExceptionMonitoringReview::class);
    }

    public function openMonitoringIssues(): HasManyThrough
    {
        return $this->hasManyThrough(
            PolicyExceptionMonitoringIssue::class,
            PolicyExceptionMonitoringReview::class,
            'policy_exception_id',
            'policy_exception_monitoring_review_id',
        )->where('policy_exception_monitoring_issues.status', '!=', GovernanceIssueStatus::Closed->value);
    }

    public function getMonitoringStatusAttribute(): PolicyExceptionMonitoringState
    {
        if (! $this->governance_fingerprint) {
            return PolicyExceptionMonitoringState::Legacy;
        }
        $hasOpenIssues = $this->relationLoaded('openMonitoringIssues')
            ? $this->openMonitoringIssues->isNotEmpty()
            : $this->openMonitoringIssues()->exists();
        if ($hasOpenIssues) {
            return PolicyExceptionMonitoringState::ActionRequired;
        }
        if ($this->status !== PolicyExceptionStatus::Approved) {
            return PolicyExceptionMonitoringState::from($this->status->value);
        }
        $hasAnyMonitoringIssue = $this->relationLoaded('monitoringReviews')
            ? $this->monitoringReviews->contains(fn (PolicyExceptionMonitoringReview $review): bool => $review->issue !== null)
            : $this->monitoringReviews()->whereHas('issue')->exists();
        if (in_array($this->latest_monitoring_outcome?->value, ['needs_action', 'revoke_recommended'], true)
            && ! $hasAnyMonitoringIssue) {
            return PolicyExceptionMonitoringState::ActionRequired;
        }
        if ($this->isExpired()) {
            return PolicyExceptionMonitoringState::Expired;
        }
        if (! $this->next_review_at) {
            return PolicyExceptionMonitoringState::ReviewRequired;
        }

        return $this->next_review_at->isPast()
            ? PolicyExceptionMonitoringState::ReviewOverdue
            : PolicyExceptionMonitoringState::MonitoringCurrent;
    }

    /**
     * Check if the exception is currently active.
     */
    public function isActive(): bool
    {
        if ($this->status !== PolicyExceptionStatus::Approved) {
            return false;
        }

        $now = now()->startOfDay();

        if ($this->effective_date && $this->effective_date->greaterThan($now)) {
            return false;
        }

        if ($this->expiration_date && $this->expiration_date->lessThan($now)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the exception has expired.
     */
    public function isExpired(): bool
    {
        if (! $this->expiration_date) {
            return false;
        }

        return $this->expiration_date->lessThan(now()->startOfDay());
    }

    /**
     * Scope a query to only include active exceptions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', PolicyExceptionStatus::Approved)
            ->where(function ($q) {
                $q->whereNull('effective_date')
                    ->orWhereDate('effective_date', '<=', today());
            })
            ->where(function ($q) {
                $q->whereNull('expiration_date')
                    ->orWhereDate('expiration_date', '>=', today());
            });
    }

    /**
     * Scope a query to only include pending exceptions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', PolicyExceptionStatus::Pending);
    }

    /**
     * Scope a query to only include expired exceptions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeExpired($query)
    {
        return $query->where('status', PolicyExceptionStatus::Approved)
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<', now());
    }
}
