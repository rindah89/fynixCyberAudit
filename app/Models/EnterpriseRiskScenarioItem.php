<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EnterpriseRiskScenarioItem extends Model
{
    protected $fillable = ['enterprise_risk_scenario_id', 'risk_id', 'risk_code_snapshot', 'risk_name_snapshot', 'parent_risk_id_snapshot', 'owner_id_snapshot', 'appetite_threshold_snapshot', 'baseline_likelihood', 'baseline_impact', 'baseline_score', 'likelihood_shift', 'impact_shift', 'stressed_likelihood', 'stressed_impact', 'stressed_score', 'rationale'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Enterprise risk scenario items are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Enterprise risk scenario items are append-only through product interfaces.'));
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(EnterpriseRiskScenario::class, 'enterprise_risk_scenario_id');
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }
}
