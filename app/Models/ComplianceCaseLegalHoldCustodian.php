<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCaseLegalHoldCustodian extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_case_legal_hold_id', 'user_id', 'recipient_snapshot'];

    protected $casts = ['recipient_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Legal-hold custodian assignments are immutable.'));
        static::deleting(fn () => throw new \LogicException('Legal-hold custodian assignments are retained evidence.'));
    }

    public function legalHold(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseLegalHold::class, 'compliance_case_legal_hold_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function acknowledgement(): HasOne
    {
        return $this->hasOne(ComplianceCaseLegalHoldAcknowledgement::class);
    }
}
