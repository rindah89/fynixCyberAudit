<?php

namespace App\Models;

use App\Enums\ModelValidationDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ModelValidationReview extends Model
{
    use HasFactory;

    protected $fillable = ['governed_model_id', 'version', 'model_version_id', 'model_snapshot', 'scope', 'testing_performed', 'findings', 'performance_summary', 'limitations_assessment', 'decision', 'conditions', 'decision_summary', 'validated_by', 'validated_at', 'valid_until', 'fingerprint'];

    protected $casts = ['model_snapshot' => 'array', 'findings' => 'array', 'decision' => ModelValidationDecision::class, 'conditions' => 'array', 'validated_at' => 'datetime', 'valid_until' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Model validation reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Model validation reviews are append-only.'));
    }

    public function governedModel(): BelongsTo
    {
        return $this->belongsTo(GovernedModel::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(GovernedModelVersion::class);
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by')->withTrashed();
    }
}
