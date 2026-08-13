<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_assessment_id',
        'risk_id',
        'name',
        'description',
        'inherent_likelihood',
        'inherent_impact',
        'inherent_risk',
        'residual_likelihood',
        'residual_impact',
        'residual_risk',
        'treatment',
        'justification',
        'ai_meta',
    ];

    protected $casts = [
        'ai_meta' => 'array',
        'inherent_likelihood' => 'integer',
        'inherent_impact' => 'integer',
        'inherent_risk' => 'integer',
        'residual_likelihood' => 'integer',
        'residual_impact' => 'integer',
        'residual_risk' => 'integer',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }
}
