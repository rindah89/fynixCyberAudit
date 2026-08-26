<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseAccessGrantRevocation extends Model
{
    protected $fillable = [
        'compliance_case_access_grant_id', 'summary', 'revoked_by', 'revoker_snapshot', 'grant_snapshot',
        'revoked_at', 'fingerprint',
    ];

    protected $casts = ['revoker_snapshot' => 'array', 'grant_snapshot' => 'array', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed access-grant revocations are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed access-grant revocations are retained.'));
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseAccessGrant::class, 'compliance_case_access_grant_id');
    }
}
