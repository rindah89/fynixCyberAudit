<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseLegalHoldAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_legal_hold_id', 'compliance_case_legal_hold_custodian_id', 'user_id',
        'hold_snapshot', 'recipient_snapshot', 'statement', 'comment', 'acknowledged_at', 'fingerprint',
    ];

    protected $casts = ['hold_snapshot' => 'array', 'recipient_snapshot' => 'array', 'acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Legal-hold acknowledgements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Legal-hold acknowledgements are retained evidence.'));
    }

    public function legalHold(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseLegalHold::class, 'compliance_case_legal_hold_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseLegalHoldCustodian::class, 'compliance_case_legal_hold_custodian_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
