<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ComplianceCaseAccessGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'version', 'grantee_id', 'grantee_snapshot', 'purpose', 'starts_at', 'ends_at',
        'granted_by', 'grantor_snapshot', 'granted_at', 'fingerprint',
    ];

    protected $casts = [
        'grantee_snapshot' => 'array', 'grantor_snapshot' => 'array', 'starts_at' => 'datetime',
        'ends_at' => 'datetime', 'granted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed access grants are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed access grants are retained.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function revocation(): HasOne
    {
        return $this->hasOne(ComplianceCaseAccessGrantRevocation::class);
    }

    public function grantee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantee_id')->withTrashed();
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by')->withTrashed();
    }

    public function isActiveAt($at = null): bool
    {
        $at = $at ?? now();

        return $this->revocation === null && $this->starts_at->lte($at) && $this->ends_at->gte($at);
    }
}
