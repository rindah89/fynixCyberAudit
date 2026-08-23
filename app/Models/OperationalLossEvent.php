<?php

namespace App\Models;

use App\Enums\OperationalLossEventCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OperationalLossEvent extends Model
{
    use HasFactory;

    protected $fillable = ['risk_id', 'business_service_id_snapshot', 'business_service_snapshot', 'reported_by', 'category', 'occurred_at', 'detected_at', 'summary', 'gross_loss', 'recoveries', 'net_loss', 'currency', 'source_reference', 'recorded_at'];

    protected $casts = [
        'category' => OperationalLossEventCategory::class,
        'business_service_snapshot' => 'array',
        'occurred_at' => 'date',
        'detected_at' => 'date',
        'gross_loss' => 'decimal:2',
        'recoveries' => 'decimal:2',
        'net_loss' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Operational loss events are immutable. Record a separate correction instead.'));
        static::deleting(fn () => throw new LogicException('Operational loss events are immutable.'));
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'business_service_id_snapshot');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
