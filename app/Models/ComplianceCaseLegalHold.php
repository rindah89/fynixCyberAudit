<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCaseLegalHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'compliance_case_event_id', 'version', 'reference', 'scope', 'systems',
        'data_categories', 'legal_basis_reference', 'preservation_start_at', 'issued_by', 'issuer_snapshot',
        'custodian_snapshot', 'case_snapshot', 'latest_event_snapshot', 'issued_at', 'fingerprint',
    ];

    protected $casts = [
        'systems' => 'array', 'data_categories' => 'array', 'issuer_snapshot' => 'array', 'custodian_snapshot' => 'array',
        'case_snapshot' => 'array', 'latest_event_snapshot' => 'array',
        'preservation_start_at' => 'datetime', 'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Governed legal holds are immutable.'));
        static::deleting(fn () => throw new \LogicException('Governed legal holds are retained evidence.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function caseEvent(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseEvent::class, 'compliance_case_event_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by')->withTrashed();
    }

    public function custodians(): HasMany
    {
        return $this->hasMany(ComplianceCaseLegalHoldCustodian::class)->orderBy('id');
    }

    public function release(): HasOne
    {
        return $this->hasOne(ComplianceCaseLegalHoldRelease::class);
    }
}
