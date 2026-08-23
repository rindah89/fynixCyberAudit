<?php

namespace App\Models;

use App\Enums\PolicyAttestationOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyAttestation extends Model
{
    protected $fillable = [
        'policy_obligation_id',
        'attested_by',
        'policy_exception_id',
        'outcome',
        'statement',
        'evidence_reference',
        'attested_at',
    ];

    protected $casts = [
        'outcome' => PolicyAttestationOutcome::class,
        'attested_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy attestations are immutable. Submit a new attestation instead.'));
        static::deleting(fn () => throw new LogicException('Policy attestations are immutable.'));
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(PolicyObligation::class, 'policy_obligation_id');
    }

    public function attestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by');
    }

    public function policyException(): BelongsTo
    {
        return $this->belongsTo(PolicyException::class);
    }
}
