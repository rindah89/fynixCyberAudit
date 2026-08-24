<?php

namespace App\Models;

use App\Enums\EsgKpiStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EsgKpiObservation extends Model
{
    use HasFactory;

    protected $fillable = ['esg_kpi_id', 'version', 'kpi_snapshot', 'observed_value', 'status', 'reason', 'notes', 'source_reference', 'observed_by', 'observed_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'kpi_snapshot' => 'array', 'observed_value' => 'decimal:6', 'status' => EsgKpiStatus::class, 'observed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ESG KPI observations are append-only.'));
        static::deleting(fn () => throw new LogicException('ESG KPI observations are retained evidence.'));
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(EsgKpi::class, 'esg_kpi_id');
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by')->withTrashed();
    }
}
