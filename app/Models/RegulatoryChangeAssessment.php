<?php

namespace App\Models;

use App\Enums\RegulatoryApplicability;
use App\Enums\RegulatoryImpact;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RegulatoryChangeAssessment extends Model
{
    use HasFactory;

    protected $fillable = ['regulatory_requirement_version_id', 'assessment_version', 'applicability', 'impact', 'summary', 'rationale', 'action_owner_id', 'action_due_at', 'requirement_snapshot', 'policy_snapshots', 'control_snapshots', 'content_fingerprint', 'assessed_by', 'assessed_at'];

    protected $casts = [
        'applicability' => RegulatoryApplicability::class, 'impact' => RegulatoryImpact::class,
        'action_due_at' => 'date', 'requirement_snapshot' => 'array', 'policy_snapshots' => 'array',
        'control_snapshots' => 'array', 'assessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Regulatory change assessments are immutable. Record a new assessment instead.'));
        static::deleting(fn () => throw new LogicException('Regulatory change assessments are immutable.'));
    }

    public function requirementVersion(): BelongsTo
    {
        return $this->belongsTo(RegulatoryRequirementVersion::class, 'regulatory_requirement_version_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by')->withTrashed();
    }

    public function actionOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_owner_id')->withTrashed();
    }
}
