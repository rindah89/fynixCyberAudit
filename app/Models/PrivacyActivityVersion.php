<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PrivacyActivityVersion extends Model
{
    use HasFactory;

    protected $fillable = ['privacy_processing_activity_id', 'version', 'activity_snapshot', 'change_summary', 'recorded_by', 'recorded_at', 'fingerprint'];

    protected $casts = ['activity_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Privacy activity versions are append-only.'));
        static::deleting(fn () => throw new LogicException('Privacy activity versions are append-only.'));
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PrivacyProcessingActivity::class, 'privacy_processing_activity_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
