<?php

namespace App\Models;

use App\Enums\ContinuityActivationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ContinuityActivationEvent extends Model
{
    use HasFactory;

    protected $fillable = ['continuity_activation_id', 'version', 'from_status', 'to_status', 'summary', 'activation_snapshot', 'recorded_by', 'recorded_at', 'fingerprint'];

    protected $casts = ['from_status' => ContinuityActivationStatus::class, 'to_status' => ContinuityActivationStatus::class, 'activation_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Continuity activation events are immutable.'));
        static::deleting(fn () => throw new LogicException('Continuity activation events are immutable.'));
    }

    public function activation(): BelongsTo
    {
        return $this->belongsTo(ContinuityActivation::class, 'continuity_activation_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
