<?php

namespace App\Models;

use App\Enums\EnterpriseScenarioProbability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EnterpriseRiskScenario extends Model
{
    protected $fillable = ['root_risk_id', 'version', 'name', 'narrative', 'horizon_months', 'probability_band', 'created_by', 'risk_count', 'baseline_score_sum', 'stressed_score_sum', 'score_delta', 'stressed_score_maximum', 'above_appetite_count', 'stressed_band_counts', 'hierarchy_snapshot', 'hierarchy_fingerprint', 'analyzed_at'];

    protected $hidden = ['hierarchy_snapshot', 'hierarchy_fingerprint'];

    protected $casts = ['probability_band' => EnterpriseScenarioProbability::class, 'stressed_band_counts' => 'array', 'hierarchy_snapshot' => 'array', 'analyzed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Enterprise risk scenarios are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Enterprise risk scenarios are append-only through product interfaces.'));
    }

    public function rootRisk(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'root_risk_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EnterpriseRiskScenarioItem::class);
    }
}
