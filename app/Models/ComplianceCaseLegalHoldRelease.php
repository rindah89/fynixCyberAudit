<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseLegalHoldRelease extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_legal_hold_id', 'released_by', 'actor_snapshot', 'hold_snapshot',
        'custodian_acknowledgement_snapshot', 'summary', 'released_at', 'fingerprint',
    ];

    protected $casts = [
        'actor_snapshot' => 'array', 'hold_snapshot' => 'array',
        'custodian_acknowledgement_snapshot' => 'array', 'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Legal-hold release evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Legal-hold release evidence is retained evidence.'));
    }

    public function legalHold(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseLegalHold::class, 'compliance_case_legal_hold_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by')->withTrashed();
    }
}
