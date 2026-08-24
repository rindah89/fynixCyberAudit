<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GovernedModelVersion extends Model
{
    use HasFactory;

    protected $fillable = ['governed_model_id', 'version', 'model_snapshot', 'change_summary', 'recorded_by', 'recorded_at', 'fingerprint'];

    protected $casts = ['model_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed model versions are append-only.'));
        static::deleting(fn () => throw new LogicException('Governed model versions are append-only.'));
    }

    public function governedModel(): BelongsTo
    {
        return $this->belongsTo(GovernedModel::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
