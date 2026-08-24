<?php

namespace App\Models;

use App\Enums\EsgDataValidationOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class EsgDataValidation extends Model
{
    use HasFactory;

    protected $fillable = ['esg_kpi_observation_id', 'version', 'observation_snapshot', 'completeness_assessment', 'accuracy_assessment', 'consistency_assessment', 'evidence_reference', 'outcome', 'summary', 'validated_by', 'validated_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'observation_snapshot' => 'array', 'outcome' => EsgDataValidationOutcome::class, 'validated_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ESG data validations are append-only.'));
        static::deleting(fn () => throw new LogicException('ESG data validations are retained evidence.'));
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(EsgKpiObservation::class, 'esg_kpi_observation_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by')->withTrashed();
    }

    public function disclosures(): BelongsToMany
    {
        return $this->belongsToMany(EsgDisclosure::class, 'esg_disclosure_validation')->withTimestamps();
    }
}
