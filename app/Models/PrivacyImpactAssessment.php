<?php

namespace App\Models;

use App\Enums\PrivacyAssessmentDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PrivacyImpactAssessment extends Model
{
    use HasFactory;

    protected $fillable = ['privacy_processing_activity_id', 'version', 'activity_version_id', 'activity_snapshot', 'necessity_assessment', 'proportionality_assessment', 'risk_summary', 'mitigations', 'residual_risk', 'decision', 'decision_summary', 'assessed_by', 'assessed_at', 'next_review_at', 'fingerprint'];

    protected $casts = ['activity_snapshot' => 'array', 'mitigations' => 'array', 'decision' => PrivacyAssessmentDecision::class, 'assessed_at' => 'datetime', 'next_review_at' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Privacy impact assessments are append-only.'));
        static::deleting(fn () => throw new LogicException('Privacy impact assessments are append-only.'));
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PrivacyProcessingActivity::class, 'privacy_processing_activity_id');
    }

    public function activityVersion(): BelongsTo
    {
        return $this->belongsTo(PrivacyActivityVersion::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by')->withTrashed();
    }
}
