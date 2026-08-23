<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VendorRiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = ['vendor_id', 'assessor_id', 'survey_id', 'version', 'likelihood', 'impact', 'inherent_score', 'residual_likelihood', 'residual_impact', 'residual_score', 'survey_score_snapshot', 'risk_categories', 'assessment_summary', 'treatment_summary', 'assessed_at'];

    protected $casts = ['risk_categories' => 'array', 'assessed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vendor risk assessments are immutable. Record a new version instead.'));
        static::deleting(fn () => throw new LogicException('Vendor risk assessments are immutable.'));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }
}
