<?php

namespace App\Models;

use App\Enums\ResilienceCriticality;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BusinessImpactAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_service_id', 'analyst_id', 'version', 'maximum_tolerable_downtime_minutes',
        'recovery_time_objective_minutes', 'recovery_point_objective_minutes', 'operational_impact',
        'regulatory_impact', 'reputational_impact', 'financial_impact_per_hour', 'rationale',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'operational_impact' => ResilienceCriticality::class,
        'regulatory_impact' => ResilienceCriticality::class,
        'reputational_impact' => ResilienceCriticality::class,
        'financial_impact_per_hour' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Impact analyses are immutable. Create a new version instead.'));
        static::deleting(fn () => throw new LogicException('Impact analyses are immutable.'));
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyst_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
