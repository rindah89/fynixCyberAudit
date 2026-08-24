<?php

namespace App\Models;

use App\Enums\PolicyExceptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyExceptionExpiration extends Model
{
    protected $fillable = [
        'policy_exception_id', 'prior_status', 'expiration_date', 'expired_at', 'reconciled_at',
        'reconciliation_id', 'source', 'exception_snapshot', 'fingerprint',
    ];

    protected $casts = [
        'prior_status' => PolicyExceptionStatus::class,
        'expiration_date' => 'date',
        'expired_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'exception_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy exception expiration evidence is append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Policy exception expiration evidence is append-only through product interfaces.'));
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(PolicyException::class, 'policy_exception_id')->withTrashed();
    }
}
