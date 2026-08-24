<?php

namespace App\Models;

use App\Enums\SystemAuthorizationMonitoringOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SystemAuthorizationPackage extends Model
{
    use HasFactory;

    protected $fillable = ['application_id', 'version', 'application_snapshot', 'system_boundary', 'impact_level', 'data_classifications', 'control_snapshot', 'risk_snapshot', 'open_findings', 'monitoring_strategy', 'review_frequency_days', 'poam_reference', 'change_summary', 'submitted_by', 'submitted_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'application_snapshot' => 'array', 'data_classifications' => 'array', 'control_snapshot' => 'array', 'risk_snapshot' => 'array', 'open_findings' => 'array', 'review_frequency_days' => 'integer', 'submitted_at' => 'datetime'];

    protected $appends = ['authorization_state', 'monitoring_state'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('System authorization packages are append-only.'));
        static::deleting(fn () => throw new LogicException('System authorization packages are retained evidence.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SystemAuthorizationDecisionRecord::class, 'system_authorization_package_id')->with('authorizer:id,name')->orderBy('version');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(SystemAuthorizationDecisionRecord::class, 'system_authorization_package_id')->latestOfMany('version');
    }

    public function monitoringReviews(): HasMany
    {
        return $this->hasMany(SystemAuthorizationMonitoringReview::class, 'system_authorization_package_id')->with('reviewer:id,name')->orderBy('version');
    }

    public function latestMonitoringReview(): HasOne
    {
        return $this->hasOne(SystemAuthorizationMonitoringReview::class, 'system_authorization_package_id')->latestOfMany('version');
    }

    public function getAuthorizationStateAttribute(): string
    {
        $decision = $this->relationLoaded('latestDecision') ? $this->latestDecision : $this->latestDecision()->first();
        if ($decision === null) {
            return 'pending_review';
        }
        if (in_array($decision->decision->value, ['authorized', 'authorized_with_conditions'], true) && $decision->valid_until?->copy()->endOfDay()->isPast()) {
            return 'authorization_expired';
        }

        return $decision->decision->value;
    }

    public function getMonitoringStateAttribute(): string
    {
        if (! in_array($this->authorization_state, ['authorized', 'authorized_with_conditions'], true)) {
            return 'not_active';
        }
        $review = $this->relationLoaded('latestMonitoringReview') ? $this->latestMonitoringReview : $this->latestMonitoringReview()->first();
        if ($review !== null && $review->outcome !== SystemAuthorizationMonitoringOutcome::Effective) {
            return 'action_required';
        }
        $decision = $this->relationLoaded('latestDecision') ? $this->latestDecision : $this->latestDecision()->first();
        $due = $review?->next_review_at ?? $decision?->decided_at?->copy()->addDays($this->review_frequency_days);

        return $due?->copy()->endOfDay()->isPast() ? 'monitoring_overdue' : 'monitoring_current';
    }
}
