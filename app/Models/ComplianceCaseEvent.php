<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot',
        'summary', 'recorded_by', 'recorded_at', 'fingerprint',
    ];

    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance case events are append-only.'));
        static::deleting(fn () => throw new LogicException('Compliance case events are append-only.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
